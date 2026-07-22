<?php

namespace App\Services\Seo;

use DOMDocument;
use DOMXPath;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class SeoCrawlerService
{
    private const MAX_REDIRECTS = 10;

    private const MAX_CHECKED_LINKS = 25;

    private const MAX_BROKEN_LINKS_SAMPLE = 5;

    private const VISIBLE_TEXT_SAMPLE_LENGTH = 500;

    private const MAX_SITEMAP_URLS = 100;

    private const MAX_CHECKED_SITEMAP_URLS = 25;

    private const MAX_CHILD_SITEMAPS = 10;

    private const CRAWL_ENABLED = true;

    private const MAX_CRAWL_PAGES = 10;

    private const MAX_CRAWL_DEPTH = 2;

    private const MAX_CRAWL_REDIRECTS = 3;

    private const GENERIC_ANCHOR_TEXTS = [
        'click here',
        'here',
        'read more',
        'learn more',
        'more',
        'voir plus',
        'cliquez ici',
    ];

    /**
     * @return array<string, mixed>
     */
    public function crawl(string $url): array
    {
        $startedAt = hrtime(true);
        [$response, $finalUrl, $redirectCount] = $this->fetchPage($url);
        $responseTimeMs = (int) round((hrtime(true) - $startedAt) / 1_000_000);
        $html = $response->body();

        $data = $this->extractSeoData($html, $finalUrl);
        $data = [
            'http_status_code' => $response->status(),
            'final_url' => $finalUrl,
            'redirect_count' => $redirectCount,
            'response_time_ms' => max(0, $responseTimeMs),
            'page_size_bytes' => strlen($html),
            ...$data,
        ];
        $data['is_indexable'] = $data['http_status_code'] === 200 && $data['is_indexable'];

        $origin = $this->origin($finalUrl);
        $robotsData = $this->analyzeRobotsTxt("{$origin}/robots.txt", $finalUrl);
        $data = [...$data, ...$robotsData];

        $sitemapData = $this->analyzeSitemap(
            $robotsData['robots_txt_sitemap_urls'],
            "{$origin}/sitemap.xml",
            $finalUrl,
        );
        $data = [...$data, ...$sitemapData];

        $multiPageData = $this->crawlInternalPages($finalUrl, $data);
        $linkCheckData = $this->checkLinks($data['checkable_links']);
        unset($data['checkable_links']);
        $data = [...$data, ...$linkCheckData, ...$multiPageData];

        return $data;
    }

    /**
     * @return array{Response, string, int}
     */
    private function fetchPage(string $url): array
    {
        $currentUrl = $url;
        $redirectCount = 0;

        while (true) {
            // Redirect targets need the same SSRF checks as the user-supplied URL.
            $this->ensureUrlIsSafe($currentUrl);
            $response = $this->httpClient()->get($currentUrl);

            if (! $this->isRedirect($response)) {
                return [$response, $currentUrl, $redirectCount];
            }

            $location = trim((string) $response->header('Location'));
            if ($location === '') {
                return [$response, $currentUrl, $redirectCount];
            }

            if ($redirectCount >= self::MAX_REDIRECTS) {
                throw new RuntimeException('The page exceeded the redirect limit.');
            }

            $currentUrl = $this->resolveUrl($currentUrl, $location);
            $redirectCount++;
        }
    }

    private function httpClient(): PendingRequest
    {
        return Http::timeout(10)
            ->connectTimeout(5)
            ->withUserAgent('AuditSEO-Crawler/2.0')
            ->withOptions(['allow_redirects' => false]);
    }

    private function linkCheckHttpClient(): PendingRequest
    {
        return Http::timeout(3)
            ->connectTimeout(2)
            ->withUserAgent('AuditSEO-Crawler/2.0')
            ->withOptions(['allow_redirects' => false]);
    }

    private function isRedirect(Response $response): bool
    {
        return in_array($response->status(), [301, 302, 303, 307, 308], true);
    }

    private function origin(string $url): string
    {
        $parts = parse_url($url);
        $scheme = strtolower((string) $parts['scheme']);
        $host = (string) $parts['host'];
        $port = isset($parts['port']) ? ":{$parts['port']}" : '';

        return "{$scheme}://{$host}{$port}";
    }

    /**
     * @return array{robots_txt_found: bool, robots_txt_status_code: ?int, robots_txt_allows_audited_url: bool, robots_txt_sitemap_urls: array<int, string>, robots_txt_disallow_rules_count: int}
     */
    private function analyzeRobotsTxt(string $robotsUrl, string $auditedUrl): array
    {
        $data = [
            'robots_txt_found' => false,
            'robots_txt_status_code' => null,
            'robots_txt_allows_audited_url' => true,
            'robots_txt_sitemap_urls' => [],
            'robots_txt_disallow_rules_count' => 0,
        ];

        try {
            [$response, $finalRobotsUrl] = $this->fetchResource($robotsUrl);
        } catch (ConnectionException|RuntimeException|ValidationException) {
            return $data;
        }

        $data['robots_txt_status_code'] = $response->status();
        $data['robots_txt_found'] = $response->successful();
        if (! $data['robots_txt_found']) {
            return $data;
        }

        $parsed = $this->parseRobotsTxt($response->body(), $finalRobotsUrl);
        $data['robots_txt_sitemap_urls'] = $parsed['sitemap_urls'];
        $data['robots_txt_disallow_rules_count'] = count($parsed['disallow_rules']);

        $path = (string) (parse_url($auditedUrl, PHP_URL_PATH) ?: '/');
        $query = (string) parse_url($auditedUrl, PHP_URL_QUERY);
        if ($query !== '') {
            $path .= "?{$query}";
        }
        $data['robots_txt_allows_audited_url'] = $this->robotsRulesAllowPath($path, $parsed['rules']);

        return $data;
    }

    /**
     * @return array{sitemap_urls: array<int, string>, disallow_rules: array<int, string>, rules: array<int, array{type: string, path: string}>}
     */
    private function parseRobotsTxt(string $contents, string $robotsUrl): array
    {
        $sitemapUrls = [];
        $disallowRules = [];
        $rules = [];
        $currentAgents = [];
        $rulesStarted = false;

        foreach (preg_split('/\R/', $contents) ?: [] as $line) {
            $line = trim((string) preg_replace('/\s*#.*$/', '', $line));
            if ($line === '' || ! str_contains($line, ':')) {
                continue;
            }

            [$directive, $value] = array_map('trim', explode(':', $line, 2));
            $directive = strtolower($directive);

            if ($directive === 'sitemap') {
                $sitemapUrl = $this->resolveCheckableHttpUrl($robotsUrl, $value);
                if ($sitemapUrl !== null) {
                    $sitemapUrls[$this->normalizeUrl($sitemapUrl)] = $sitemapUrl;
                }

                continue;
            }

            if ($directive === 'user-agent') {
                if ($rulesStarted) {
                    $currentAgents = [];
                    $rulesStarted = false;
                }

                $currentAgents[] = strtolower($value);

                continue;
            }

            if ($currentAgents === []) {
                continue;
            }

            $rulesStarted = true;
            if (! in_array('*', $currentAgents, true)
                || ! in_array($directive, ['allow', 'disallow'], true)
                || $value === '') {
                continue;
            }

            $rules[] = ['type' => $directive, 'path' => $value];
            if ($directive === 'disallow') {
                $disallowRules[] = $value;
            }
        }

        return [
            'sitemap_urls' => array_values($sitemapUrls),
            'disallow_rules' => $disallowRules,
            'rules' => $rules,
        ];
    }

    /**
     * @param  array<int, array{type: string, path: string}>  $rules
     */
    private function robotsRulesAllowPath(string $path, array $rules): bool
    {
        $matchedRule = null;
        $matchedLength = -1;

        foreach ($rules as $rule) {
            if (! $this->robotsRuleMatches($path, $rule['path'])) {
                continue;
            }

            $ruleLength = mb_strlen(str_replace('*', '', rtrim($rule['path'], '$')));
            if ($ruleLength > $matchedLength
                || ($ruleLength === $matchedLength && $rule['type'] === 'allow')) {
                $matchedRule = $rule;
                $matchedLength = $ruleLength;
            }
        }

        return $matchedRule === null || $matchedRule['type'] === 'allow';
    }

    private function robotsRuleMatches(string $path, string $rule): bool
    {
        $endsAtPathEnd = str_ends_with($rule, '$');
        if ($endsAtPathEnd) {
            $rule = substr($rule, 0, -1);
        }

        $pattern = str_replace('\\*', '.*', preg_quote($rule, '#'));

        return preg_match('#^'.$pattern.($endsAtPathEnd ? '$' : '').'#u', $path) === 1;
    }

    /**
     * @param  array<int, string>  $robotsSitemapUrls
     * @return array<string, mixed>
     */
    private function analyzeSitemap(array $robotsSitemapUrls, string $fallbackUrl, string $auditedUrl): array
    {
        $data = [
            'sitemap_xml_found' => false,
            'sitemap_xml_status_code' => null,
            'sitemap_xml_is_valid' => false,
            'sitemap_urls_count' => 0,
            'sitemap_contains_audited_url' => false,
            'sitemap_https_urls_count' => 0,
            'sitemap_non_https_urls_count' => 0,
            'sitemap_checked_urls_count' => 0,
            'sitemap_broken_urls_count' => 0,
            'sitemap_broken_urls_sample' => [],
        ];
        $sitemapUrl = $robotsSitemapUrls[0] ?? $fallbackUrl;

        try {
            [$response, $finalSitemapUrl] = $this->fetchResource($sitemapUrl);
        } catch (ConnectionException|RuntimeException|ValidationException) {
            return $data;
        }

        $data['sitemap_xml_status_code'] = $response->status();
        $data['sitemap_xml_found'] = $response->successful();
        if (! $data['sitemap_xml_found']) {
            return $data;
        }

        $parsed = $this->parseSitemapXml($response->body());
        if ($parsed === null) {
            return $data;
        }

        $data['sitemap_xml_is_valid'] = true;
        $sitemapUrls = [];
        if ($parsed['type'] === 'urlset') {
            $this->addSitemapLocations($sitemapUrls, $parsed['locations'], $finalSitemapUrl);
        } else {
            foreach (array_slice($parsed['locations'], 0, self::MAX_CHILD_SITEMAPS) as $childLocation) {
                if (count($sitemapUrls) >= self::MAX_SITEMAP_URLS) {
                    break;
                }

                $childUrl = $this->resolveCheckableHttpUrl($finalSitemapUrl, $childLocation);
                if ($childUrl === null) {
                    continue;
                }

                try {
                    [$childResponse, $finalChildUrl] = $this->fetchResource($childUrl);
                } catch (ConnectionException|RuntimeException|ValidationException) {
                    continue;
                }

                if (! $childResponse->successful()) {
                    continue;
                }

                $childSitemap = $this->parseSitemapXml($childResponse->body());
                if ($childSitemap !== null && $childSitemap['type'] === 'urlset') {
                    $this->addSitemapLocations($sitemapUrls, $childSitemap['locations'], $finalChildUrl);
                }
            }
        }

        $sitemapUrls = array_values($sitemapUrls);
        $data['sitemap_urls_count'] = count($sitemapUrls);
        $auditedUrlKey = $this->normalizeUrl($auditedUrl);
        $data['sitemap_contains_audited_url'] = in_array($auditedUrlKey, array_map(
            fn (string $url): string => $this->normalizeUrl($url),
            $sitemapUrls,
        ), true);

        foreach ($sitemapUrls as $url) {
            if (strtolower((string) parse_url($url, PHP_URL_SCHEME)) === 'https') {
                $data['sitemap_https_urls_count']++;
            } else {
                $data['sitemap_non_https_urls_count']++;
            }
        }

        $checkData = $this->checkSitemapUrls($sitemapUrls);

        return [...$data, ...$checkData];
    }

    /**
     * @return null|array{type: string, locations: array<int, string>}
     */
    private function parseSitemapXml(string $xml): ?array
    {
        $document = new DOMDocument;
        $previousErrorHandling = libxml_use_internal_errors(true);
        $loaded = $document->loadXML($xml, LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previousErrorHandling);

        if (! $loaded || $document->documentElement === null) {
            return null;
        }

        $rootName = strtolower($document->documentElement->localName);
        if (! in_array($rootName, ['urlset', 'sitemapindex'], true)) {
            return null;
        }

        $xpath = new DOMXPath($document);
        $query = $rootName === 'urlset'
            ? '/*[local-name()="urlset"]/*[local-name()="url"]/*[local-name()="loc"]'
            : '/*[local-name()="sitemapindex"]/*[local-name()="sitemap"]/*[local-name()="loc"]';
        $locations = [];
        $locationLimit = $rootName === 'urlset' ? self::MAX_SITEMAP_URLS : self::MAX_CHILD_SITEMAPS;
        foreach ($xpath->query($query) as $locationNode) {
            if (count($locations) >= $locationLimit) {
                break;
            }

            $location = trim((string) $locationNode->textContent);
            if ($location !== '') {
                $locations[] = $location;
            }
        }

        return [
            'type' => $rootName,
            'locations' => $locations,
        ];
    }

    /**
     * @param  array<string, string>  $sitemapUrls
     * @param  array<int, string>  $locations
     */
    private function addSitemapLocations(array &$sitemapUrls, array $locations, string $sitemapUrl): void
    {
        foreach ($locations as $location) {
            if (count($sitemapUrls) >= self::MAX_SITEMAP_URLS) {
                return;
            }

            $url = $this->resolveCheckableHttpUrl($sitemapUrl, $location);
            if ($url !== null) {
                $sitemapUrls[$this->normalizeUrl($url)] = $url;
            }
        }
    }

    /**
     * @param  array<int, string>  $urls
     * @return array{sitemap_checked_urls_count: int, sitemap_broken_urls_count: int, sitemap_broken_urls_sample: array<int, string>}
     */
    private function checkSitemapUrls(array $urls): array
    {
        $checkedCount = 0;
        $brokenCount = 0;
        $brokenSample = [];

        foreach (array_slice($urls, 0, self::MAX_CHECKED_SITEMAP_URLS) as $url) {
            try {
                $this->ensureUrlIsSafe($url);
                $response = $this->fetchLink($url);
            } catch (ValidationException) {
                continue;
            } catch (ConnectionException|RuntimeException) {
                $checkedCount++;
                $brokenCount++;
                if (count($brokenSample) < self::MAX_BROKEN_LINKS_SAMPLE) {
                    $brokenSample[] = $url;
                }

                continue;
            }

            $checkedCount++;
            if ($response->status() >= 400) {
                $brokenCount++;
                if (count($brokenSample) < self::MAX_BROKEN_LINKS_SAMPLE) {
                    $brokenSample[] = $url;
                }
            }
        }

        return [
            'sitemap_checked_urls_count' => $checkedCount,
            'sitemap_broken_urls_count' => $brokenCount,
            'sitemap_broken_urls_sample' => $brokenSample,
        ];
    }

    /**
     * @param  array<string, mixed>  $mainPageData
     * @return array<string, mixed>
     */
    private function crawlInternalPages(string $finalUrl, array $mainPageData): array
    {
        $maxPages = self::MAX_CRAWL_PAGES;
        $maxDepth = self::MAX_CRAWL_DEPTH;
        $host = strtolower((string) parse_url($finalUrl, PHP_URL_HOST));
        $seen = [$this->normalizeUrl($finalUrl) => true];
        $discovered = [];
        $queue = [];
        $crawledPages = [$this->compactCrawledPage($finalUrl, (int) $mainPageData['http_status_code'], 0, $mainPageData)];

        foreach ($mainPageData['checkable_links'] as $link) {
            $this->queueInternalCrawlUrl($queue, $discovered, $seen, $link, $host, 1, $maxDepth);
        }

        while ($queue !== [] && count($crawledPages) < $maxPages) {
            $item = array_shift($queue);
            $pageUrl = $item['url'];
            $depth = $item['depth'];

            try {
                [$response, $finalPageUrl] = $this->fetchCrawlPage($pageUrl);
            } catch (ConnectionException|RuntimeException|ValidationException) {
                continue;
            }

            $pageData = $this->extractSeoData($response->body(), $finalPageUrl);
            $pageData['is_indexable'] = $response->status() === 200 && $pageData['is_indexable'];
            $crawledPages[] = $this->compactCrawledPage($finalPageUrl, $response->status(), $depth, $pageData);

            if ($depth >= $maxDepth || ! $response->successful()) {
                continue;
            }

            foreach ($pageData['checkable_links'] as $link) {
                $this->queueInternalCrawlUrl($queue, $discovered, $seen, $link, $host, $depth + 1, $maxDepth);
            }
        }

        return [
            'crawl_enabled' => self::CRAWL_ENABLED,
            'crawl_max_pages' => $maxPages,
            'crawl_max_depth' => $maxDepth,
            'crawled_pages_count' => count($crawledPages),
            'discovered_internal_urls_count' => count($discovered),
            'crawled_pages' => $crawledPages,
            ...$this->summarizeCrawledPages($crawledPages),
        ];
    }

    /**
     * @param  array<int, array{url: string, depth: int}>  $queue
     * @param  array<string, string>  $discovered
     * @param  array<string, bool>  $seen
     */
    private function queueInternalCrawlUrl(
        array &$queue,
        array &$discovered,
        array &$seen,
        string $url,
        string $host,
        int $depth,
        int $maxDepth,
    ): void {
        if ($depth > $maxDepth || strtolower((string) parse_url($url, PHP_URL_HOST)) !== $host) {
            return;
        }

        $key = $this->normalizeUrl($url);
        $discovered[$key] = $url;
        if (isset($seen[$key])) {
            return;
        }

        $seen[$key] = true;
        $queue[] = ['url' => $url, 'depth' => $depth];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{url: string, status_code: int, depth: int, title: ?string, meta_description: ?string, h1: ?string, word_count: int, is_indexable: bool}
     */
    private function compactCrawledPage(string $url, int $statusCode, int $depth, array $data): array
    {
        return [
            'url' => $url,
            'status_code' => $statusCode,
            'depth' => $depth,
            'title' => $data['title'],
            'meta_description' => $data['meta_description'],
            'h1' => $data['h1_texts'][0] ?? null,
            'word_count' => (int) $data['word_count'],
            'is_indexable' => (bool) $data['is_indexable'],
        ];
    }

    /**
     * @param  array<int, array{url: string, status_code: int, depth: int, title: ?string, meta_description: ?string, h1: ?string, word_count: int, is_indexable: bool}>  $pages
     * @return array<string, int>
     */
    private function summarizeCrawledPages(array $pages): array
    {
        $httpErrors = 0;
        $missingTitles = 0;
        $missingMetaDescriptions = 0;
        $missingH1 = 0;
        $noindex = 0;
        $lowWordCount = 0;
        $titles = [];
        $metaDescriptions = [];
        $h1s = [];

        foreach ($pages as $page) {
            if ($page['status_code'] === 200 && $page['title'] !== null) {
                $titles[] = $this->normalizeComparableText($page['title']);
            }

            if ($page['status_code'] === 200 && $page['meta_description'] !== null) {
                $metaDescriptions[] = $this->normalizeComparableText($page['meta_description']);
            }

            if ($page['status_code'] === 200 && $page['h1'] !== null) {
                $h1s[] = $this->normalizeComparableText($page['h1']);
            }

            if ($page['depth'] === 0) {
                continue;
            }

            if ($page['status_code'] >= 400) {
                $httpErrors++;
            }

            if ($page['status_code'] === 200 && ! $page['is_indexable']) {
                $noindex++;
            }

            if ($page['status_code'] !== 200) {
                continue;
            }

            if ($page['title'] === null) {
                $missingTitles++;
            }

            if ($page['meta_description'] === null) {
                $missingMetaDescriptions++;
            }

            if ($page['h1'] === null) {
                $missingH1++;
            }

            if ($page['word_count'] < 300) {
                $lowWordCount++;
            }
        }

        return [
            'pages_with_http_errors_count' => $httpErrors,
            'pages_with_missing_title_count' => $missingTitles,
            'pages_with_missing_meta_description_count' => $missingMetaDescriptions,
            'pages_with_missing_h1_count' => $missingH1,
            'pages_with_noindex_count' => $noindex,
            'pages_with_low_word_count_count' => $lowWordCount,
            'duplicate_titles_count' => $this->duplicateValueCount($titles),
            'duplicate_meta_descriptions_count' => $this->duplicateValueCount($metaDescriptions),
            'duplicate_h1_count' => $this->duplicateValueCount($h1s),
        ];
    }

    /**
     * @param  array<int, string>  $values
     */
    private function duplicateValueCount(array $values): int
    {
        $counts = array_count_values(array_filter($values, fn (string $value): bool => $value !== ''));
        $duplicates = 0;

        foreach ($counts as $count) {
            if ($count > 1) {
                $duplicates += $count - 1;
            }
        }

        return $duplicates;
    }

    /**
     * @return array{Response, string}
     */
    private function fetchResource(string $url): array
    {
        $currentUrl = $url;
        $redirectCount = 0;

        while (true) {
            $this->ensureUrlIsSafe($currentUrl);
            $response = $this->httpClient()->get($currentUrl);

            if (! $this->isRedirect($response)) {
                return [$response, $currentUrl];
            }

            $location = trim((string) $response->header('Location'));
            if ($location === '') {
                return [$response, $currentUrl];
            }

            if ($redirectCount >= self::MAX_REDIRECTS) {
                throw new RuntimeException('The resource exceeded the redirect limit.');
            }

            $redirectUrl = $this->resolveCheckableHttpUrl($currentUrl, $location);
            if ($redirectUrl === null) {
                return [$response, $currentUrl];
            }

            $currentUrl = $redirectUrl;
            $redirectCount++;
        }
    }

    private function ensureUrlIsSafe(string $url): void
    {
        $parts = parse_url($url);
        $scheme = strtolower($parts['scheme'] ?? '');
        $host = strtolower(trim($parts['host'] ?? '', '[]'));

        if (! in_array($scheme, ['http', 'https'], true) || $host === '') {
            $this->rejectUnsafeUrl();
        }

        if ($host === 'localhost' || str_ends_with($host, '.localhost')) {
            $this->rejectUnsafeUrl();
        }

        foreach (['.local', '.internal', '.lan', '.home'] as $internalSuffix) {
            if (str_ends_with($host, $internalSuffix)) {
                $this->rejectUnsafeUrl();
            }
        }

        if (filter_var($host, FILTER_VALIDATE_IP)) {
            if (! $this->isPublicIp($host)) {
                $this->rejectUnsafeUrl();
            }

            return;
        }

        $resolvedAddresses = gethostbynamel($host) ?: [];

        foreach ($resolvedAddresses as $address) {
            if (! $this->isPublicIp($address)) {
                $this->rejectUnsafeUrl();
            }
        }
    }

    private function isPublicIp(string $address): bool
    {
        return filter_var(
            $address,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE,
        ) !== false;
    }

    private function rejectUnsafeUrl(): never
    {
        throw ValidationException::withMessages([
            'url' => ['The URL must point to a public HTTP or HTTPS address.'],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function extractSeoData(string $html, string $url): array
    {
        if (trim($html) === '') {
            $html = '<!doctype html><html><body></body></html>';
        }

        $document = new DOMDocument;
        $previousErrorHandling = libxml_use_internal_errors(true);
        $document->loadHTML($html, LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previousErrorHandling);

        $xpath = new DOMXPath($document);
        $title = trim((string) $xpath->evaluate('string(//title[1])'));
        $description = trim((string) $xpath->evaluate(
            "string(//meta[translate(@name, 'ABCDEFGHIJKLMNOPQRSTUVWXYZ', 'abcdefghijklmnopqrstuvwxyz') = 'description'][1]/@content)",
        ));
        $canonical = trim((string) $xpath->evaluate(
            "string(//link[contains(concat(' ', translate(normalize-space(@rel), 'ABCDEFGHIJKLMNOPQRSTUVWXYZ', 'abcdefghijklmnopqrstuvwxyz'), ' '), ' canonical ')][1]/@href)",
        ));
        $canonicalUrl = $canonical !== '' ? $this->resolveUrl($url, $canonical) : null;
        $metaRobots = trim((string) $xpath->evaluate(
            "string(//meta[translate(@name, 'ABCDEFGHIJKLMNOPQRSTUVWXYZ', 'abcdefghijklmnopqrstuvwxyz') = 'robots'][1]/@content)",
        ));
        $robotsDirectives = strtolower($metaRobots);
        $images = $xpath->query('//img');
        $imagesMissingAlt = $xpath->query('//img[not(@alt) or normalize-space(@alt) = ""]');
        $links = $xpath->query('//a[@href]');
        $linkData = $this->analyzeLinks($links, $url);
        $headingData = $this->analyzeHeadings($xpath);
        $visibleText = $this->extractVisibleText($xpath);
        $wordCount = $this->countWords($visibleText);
        $imagesCount = $images->length;
        $imagesMissingAltCount = $imagesMissingAlt->length;

        return [
            'title' => $title !== '' ? $title : null,
            'title_length' => mb_strlen($title),
            'meta_description' => $description !== '' ? $description : null,
            'meta_description_length' => mb_strlen($description),
            'word_count' => $wordCount,
            'visible_text_sample' => mb_substr($visibleText, 0, self::VISIBLE_TEXT_SAMPLE_LENGTH),
            'canonical_url' => $canonicalUrl,
            'canonical_matches_final_url' => $canonicalUrl !== null
                ? $this->normalizeUrl($canonicalUrl) === $this->normalizeUrl($url)
                : false,
            'meta_robots' => $metaRobots !== '' ? $metaRobots : null,
            'is_indexable' => ! preg_match('/(?:^|[\s,])noindex(?:[\s,]|$)/i', $robotsDirectives),
            'html_lang' => $this->nullableTrimmed((string) $xpath->evaluate('string(/html[1]/@lang)')),
            'viewport_found' => $xpath->query(
                "//meta[translate(@name, 'ABCDEFGHIJKLMNOPQRSTUVWXYZ', 'abcdefghijklmnopqrstuvwxyz') = 'viewport']",
            )->length > 0,
            'h1_count' => $xpath->query('//h1')->length,
            'h2_count' => $xpath->query('//h2')->length,
            'h3_count' => $xpath->query('//h3')->length,
            'h4_count' => $xpath->query('//h4')->length,
            'h5_count' => $xpath->query('//h5')->length,
            'h6_count' => $xpath->query('//h6')->length,
            ...$headingData,
            'title_matches_h1' => $this->titleMatchesH1($title, $headingData['h1_texts']),
            'images_count' => $imagesCount,
            'images_missing_alt_count' => $imagesMissingAltCount,
            'images_alt_missing_ratio' => $imagesCount > 0
                ? round($imagesMissingAltCount / $imagesCount, 4)
                : 0.0,
            'links_count' => $links->length,
            ...$linkData,
            'uses_https' => strtolower((string) parse_url($url, PHP_URL_SCHEME)) === 'https',
        ];
    }

    /**
     * @return array{h1_texts: array<int, string>, h2_texts: array<int, string>, heading_structure: array<int, array{tag: string, text: string}>}
     */
    private function analyzeHeadings(DOMXPath $xpath): array
    {
        $h1Texts = [];
        $h2Texts = [];
        $headingStructure = [];

        foreach ($xpath->query('//h1 | //h2 | //h3 | //h4 | //h5 | //h6') as $heading) {
            $tag = strtolower($heading->nodeName);
            $text = $this->normalizeWhitespace((string) $heading->textContent);
            $headingStructure[] = ['tag' => $tag, 'text' => $text];

            if ($tag === 'h1') {
                $h1Texts[] = $text;
            } elseif ($tag === 'h2') {
                $h2Texts[] = $text;
            }
        }

        return [
            'h1_texts' => $h1Texts,
            'h2_texts' => $h2Texts,
            'heading_structure' => $headingStructure,
        ];
    }

    private function extractVisibleText(DOMXPath $xpath): string
    {
        $parts = [];
        $textNodes = $xpath->query(
            '//body//text()[not(ancestor::script) and not(ancestor::style) and not(ancestor::noscript) and not(ancestor::svg)]',
        );

        foreach ($textNodes as $textNode) {
            $text = $this->normalizeWhitespace((string) $textNode->nodeValue);
            if ($text !== '') {
                $parts[] = $text;
            }
        }

        return $this->normalizeWhitespace(implode(' ', $parts));
    }

    private function countWords(string $text): int
    {
        return (int) preg_match_all("/[\\p{L}\\p{N}]+(?:['’\x{2010}-\x{2015}][\\p{L}\\p{N}]+)*/u", $text);
    }

    /**
     * @param  array<int, string>  $h1Texts
     */
    private function titleMatchesH1(string $title, array $h1Texts): bool
    {
        $normalizedTitle = $this->normalizeComparableText($title);
        if ($normalizedTitle === '') {
            return false;
        }

        foreach ($h1Texts as $h1Text) {
            $normalizedH1 = $this->normalizeComparableText($h1Text);
            if ($normalizedH1 !== '' && (
                $normalizedTitle === $normalizedH1
                || str_contains($normalizedTitle, $normalizedH1)
                || str_contains($normalizedH1, $normalizedTitle)
            )) {
                return true;
            }
        }

        return false;
    }

    private function normalizeComparableText(string $text): string
    {
        $text = mb_strtolower($text);
        $text = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $text) ?? '';

        return $this->normalizeWhitespace($text);
    }

    private function normalizeWhitespace(string $text): string
    {
        return trim(preg_replace('/\s+/u', ' ', $text) ?? '');
    }

    /**
     * @return array{internal_links_count: int, external_links_count: int, nofollow_links_count: int, empty_anchor_links_count: int, generic_anchor_links_count: int, checkable_links: array<int, string>}
     */
    private function analyzeLinks(\DOMNodeList $links, string $finalUrl): array
    {
        $internalCount = 0;
        $externalCount = 0;
        $nofollowCount = 0;
        $emptyAnchorCount = 0;
        $genericAnchorCount = 0;
        $checkableLinks = [];
        $finalHost = strtolower((string) parse_url($finalUrl, PHP_URL_HOST));

        foreach ($links as $link) {
            $href = trim((string) $link->attributes?->getNamedItem('href')?->nodeValue);
            $anchorText = preg_replace('/\s+/u', ' ', (string) $link->textContent) ?? '';
            $anchorText = trim($anchorText);

            if ($anchorText === '') {
                $emptyAnchorCount++;
            } elseif (in_array(strtolower($anchorText), self::GENERIC_ANCHOR_TEXTS, true)) {
                $genericAnchorCount++;
            }

            $rel = strtolower((string) $link->attributes?->getNamedItem('rel')?->nodeValue);
            if (preg_match('/(?:^|\s)nofollow(?:\s|$)/', $rel)) {
                $nofollowCount++;
            }

            $resolvedUrl = $this->resolveCheckableHttpUrl($finalUrl, $href);
            if ($resolvedUrl === null) {
                continue;
            }

            $linkHost = strtolower((string) parse_url($resolvedUrl, PHP_URL_HOST));
            if ($linkHost === $finalHost) {
                $internalCount++;
            } else {
                $externalCount++;
            }

            $checkableLinks[$this->normalizeUrl($resolvedUrl)] ??= $resolvedUrl;
        }

        return [
            'internal_links_count' => $internalCount,
            'external_links_count' => $externalCount,
            'nofollow_links_count' => $nofollowCount,
            'empty_anchor_links_count' => $emptyAnchorCount,
            'generic_anchor_links_count' => $genericAnchorCount,
            'checkable_links' => array_values($checkableLinks),
        ];
    }

    private function resolveCheckableHttpUrl(string $baseUrl, string $href): ?string
    {
        if ($href === '' || str_starts_with($href, '#')) {
            return null;
        }

        $referenceScheme = strtolower((string) parse_url($href, PHP_URL_SCHEME));
        if ($referenceScheme !== '' && ! in_array($referenceScheme, ['http', 'https'], true)) {
            return null;
        }

        $resolvedUrl = $this->resolveUrl($baseUrl, $href);
        $parts = parse_url($resolvedUrl);
        if ($parts === false
            || ! in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true)
            || ($parts['host'] ?? '') === ''
            || filter_var($resolvedUrl, FILTER_VALIDATE_URL) === false) {
            return null;
        }

        $fragmentPosition = strpos($resolvedUrl, '#');

        return $fragmentPosition === false ? $resolvedUrl : substr($resolvedUrl, 0, $fragmentPosition);
    }

    /**
     * @param  array<int, string>  $links
     * @return array{checked_links_count: int, broken_links_count: int, broken_links_sample: array<int, string>}
     */
    private function checkLinks(array $links): array
    {
        $checkedCount = 0;
        $brokenCount = 0;
        $brokenSample = [];

        foreach (array_slice($links, 0, self::MAX_CHECKED_LINKS) as $link) {
            try {
                $this->ensureUrlIsSafe($link);
                $response = $this->fetchLink($link);
            } catch (ValidationException) {
                // A page cannot make the crawler request an unsafe link target.
                continue;
            } catch (ConnectionException|RuntimeException) {
                $checkedCount++;
                $brokenCount++;
                if (count($brokenSample) < self::MAX_BROKEN_LINKS_SAMPLE) {
                    $brokenSample[] = $link;
                }

                continue;
            }

            $checkedCount++;
            if ($response->status() >= 400) {
                $brokenCount++;
                if (count($brokenSample) < self::MAX_BROKEN_LINKS_SAMPLE) {
                    $brokenSample[] = $link;
                }
            }
        }

        return [
            'checked_links_count' => $checkedCount,
            'broken_links_count' => $brokenCount,
            'broken_links_sample' => $brokenSample,
        ];
    }

    private function fetchLink(string $url): Response
    {
        $currentUrl = $url;
        $redirectCount = 0;

        while (true) {
            $this->ensureUrlIsSafe($currentUrl);
            $response = $this->linkCheckHttpClient()->get($currentUrl);

            if (! $this->isRedirect($response)) {
                return $response;
            }

            $location = trim((string) $response->header('Location'));
            if ($location === '') {
                return $response;
            }

            if ($redirectCount >= self::MAX_REDIRECTS) {
                throw new RuntimeException('The link exceeded the redirect limit.');
            }

            $redirectUrl = $this->resolveCheckableHttpUrl($currentUrl, $location);
            if ($redirectUrl === null) {
                return $response;
            }

            $currentUrl = $redirectUrl;
            $redirectCount++;
        }
    }

    /**
     * @return array{Response, string}
     */
    private function fetchCrawlPage(string $url): array
    {
        $currentUrl = $url;
        $redirectCount = 0;

        while (true) {
            $this->ensureUrlIsSafe($currentUrl);
            $response = $this->linkCheckHttpClient()->get($currentUrl);

            if (! $this->isRedirect($response)) {
                return [$response, $currentUrl];
            }

            $location = trim((string) $response->header('Location'));
            if ($location === '') {
                return [$response, $currentUrl];
            }

            if ($redirectCount >= self::MAX_CRAWL_REDIRECTS) {
                throw new RuntimeException('The crawled page exceeded the redirect limit.');
            }

            $redirectUrl = $this->resolveCheckableHttpUrl($currentUrl, $location);
            if ($redirectUrl === null) {
                return [$response, $currentUrl];
            }

            $currentUrl = $redirectUrl;
            $redirectCount++;
        }
    }

    private function nullableTrimmed(string $value): ?string
    {
        $value = trim($value);

        return $value !== '' ? $value : null;
    }

    private function resolveUrl(string $baseUrl, string $reference): string
    {
        $reference = trim($reference);
        if (preg_match('#^https?://#i', $reference)) {
            return $reference;
        }

        $base = parse_url($baseUrl);
        $scheme = (string) ($base['scheme'] ?? '');
        $host = (string) ($base['host'] ?? '');
        $port = isset($base['port']) ? ":{$base['port']}" : '';

        if (str_starts_with($reference, '//')) {
            return "{$scheme}:{$reference}";
        }

        if (str_starts_with($reference, '#')) {
            $baseUrl = preg_replace('/#.*$/', '', $baseUrl) ?? $baseUrl;

            return $baseUrl.$reference;
        }

        if (str_starts_with($reference, '?')) {
            $path = (string) ($base['path'] ?? '/');

            return "{$scheme}://{$host}{$port}{$path}{$reference}";
        }

        $path = str_starts_with($reference, '/')
            ? $reference
            : preg_replace('#/[^/]*$#', '/', (string) ($base['path'] ?? '/')).$reference;

        $segments = [];
        foreach (explode('/', (string) $path) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                array_pop($segments);
            } else {
                $segments[] = $segment;
            }
        }

        return "{$scheme}://{$host}{$port}/".implode('/', $segments);
    }

    private function normalizeUrl(string $url): string
    {
        $parts = parse_url($url);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower((string) ($parts['host'] ?? ''));
        $port = isset($parts['port']) && ! (($scheme === 'http' && $parts['port'] === 80) || ($scheme === 'https' && $parts['port'] === 443))
            ? ":{$parts['port']}"
            : '';
        $path = (string) ($parts['path'] ?? '/');
        $path = $path === '' ? '/' : $path;
        $path = $path !== '/' ? rtrim($path, '/') : $path;
        $query = isset($parts['query']) ? "?{$parts['query']}" : '';

        return "{$scheme}://{$host}{$port}{$path}{$query}";
    }
}
