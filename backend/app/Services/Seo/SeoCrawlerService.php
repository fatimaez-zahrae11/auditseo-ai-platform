<?php

namespace App\Services\Seo;

use App\Security\PublicUrlPolicy;
use DOMDocument;
use DOMXPath;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use JsonException;
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

    private const MAX_STRUCTURED_DATA_ERRORS_SAMPLE = 5;

    private const MAX_SITE_QUALITY_SAMPLE = 5;

    private const MIN_CONTENT_FINGERPRINT_WORDS = 100;

    private const IMPORTANT_SCHEMA_TYPES = [
        'Organization',
        'WebSite',
        'BreadcrumbList',
        'Article',
        'BlogPosting',
        'Product',
        'FAQPage',
        'LocalBusiness',
        'Person',
    ];

    private const GENERIC_ANCHOR_TEXTS = [
        'click here',
        'here',
        'read more',
        'learn more',
        'more',
        'voir plus',
        'cliquez ici',
    ];

    public function __construct(private readonly PublicUrlPolicy $urlPolicy) {}

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
            ...$this->performanceMetadata($response, $html),
            ...$data,
        ];
        $data['performance_warnings_count'] = $this->performanceWarningsCount($data);
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
        $siteQualityData = $this->analyzeSiteWideQuality(
            $multiPageData['crawled_pages'],
            $sitemapData['_sitemap_urls'],
            $multiPageData['_discovered_internal_urls'],
            $finalUrl,
        );
        $linkCheckData = $this->checkLinks($data['checkable_links']);
        unset(
            $data['checkable_links'],
            $data['content_fingerprint'],
            $data['_sitemap_urls'],
            $multiPageData['_discovered_internal_urls'],
        );
        $data = [...$data, ...$linkCheckData, ...$multiPageData, ...$siteQualityData];

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
            $target = $this->urlPolicy->validate($currentUrl);
            $response = $this->httpClient($target)->get($currentUrl);

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

    /**
     * @param  array{host: string, port: int, addresses: list<string>, is_ip_literal: bool}  $target
     */
    private function httpClient(array $target): PendingRequest
    {
        return Http::timeout(10)
            ->connectTimeout(5)
            ->withUserAgent('AuditSEO-Crawler/2.0')
            ->withOptions([
                'allow_redirects' => false,
                ...$this->urlPolicy->connectionOptions($target),
            ]);
    }

    /**
     * @param  array{host: string, port: int, addresses: list<string>, is_ip_literal: bool}  $target
     */
    private function linkCheckHttpClient(array $target): PendingRequest
    {
        return Http::timeout(3)
            ->connectTimeout(2)
            ->withUserAgent('AuditSEO-Crawler/2.0')
            ->withOptions([
                'allow_redirects' => false,
                ...$this->urlPolicy->connectionOptions($target),
            ]);
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
            'sitemap_urls_sample' => [],
            '_sitemap_urls' => [],
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
        $data['_sitemap_urls'] = $sitemapUrls;
        $data['sitemap_urls_sample'] = array_slice($sitemapUrls, 0, self::MAX_SITE_QUALITY_SAMPLE);
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
                $startedAt = hrtime(true);
                [$response, $finalPageUrl] = $this->fetchCrawlPage($pageUrl);
                $responseTimeMs = (int) round((hrtime(true) - $startedAt) / 1_000_000);
            } catch (ConnectionException|RuntimeException|ValidationException) {
                continue;
            }

            $body = $response->body();
            $pageData = $this->extractSeoData($body, $finalPageUrl);
            $pageData['response_time_ms'] = max(0, $responseTimeMs);
            $pageData['page_size_bytes'] = strlen($body);
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
            '_discovered_internal_urls' => array_values($discovered),
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
     * @return array{url: string, status_code: int, depth: int, title: ?string, meta_description: ?string, h1: ?string, word_count: int, is_indexable: bool, response_time_ms: int, page_size_bytes: int, structured_data_found: bool, schema_types: array<int, string>, canonical_url: ?string, content_fingerprint: ?string}
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
            'response_time_ms' => max(0, (int) ($data['response_time_ms'] ?? 0)),
            'page_size_bytes' => max(0, (int) ($data['page_size_bytes'] ?? 0)),
            'structured_data_found' => (bool) ($data['structured_data_found'] ?? false),
            'schema_types' => array_values($data['schema_types'] ?? []),
            'canonical_url' => $data['canonical_url'] ?? null,
            'content_fingerprint' => $data['content_fingerprint'] ?? null,
        ];
    }

    /**
     * @param  array<int, array{url: string, status_code: int, depth: int, title: ?string, meta_description: ?string, h1: ?string, word_count: int, is_indexable: bool, response_time_ms: int, page_size_bytes: int, structured_data_found: bool, schema_types: array<int, string>, canonical_url: ?string, content_fingerprint: ?string}>  $pages
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
     * @param  array<int, array<string, mixed>>  $pages
     * @param  array<int, string>  $sitemapUrls
     * @param  array<int, string>  $discoveredUrls
     * @return array<string, mixed>
     */
    private function analyzeSiteWideQuality(
        array $pages,
        array $sitemapUrls,
        array $discoveredUrls,
        string $auditedUrl,
    ): array {
        $titleGroups = $this->duplicateTextGroups($pages, 'title');
        $metaDescriptionGroups = $this->duplicateTextGroups($pages, 'meta_description');
        $h1Groups = $this->duplicateTextGroups($pages, 'h1');
        $contentGroups = $this->duplicateFingerprintGroups($pages);
        $duplicateContentCount = $this->duplicateOccurrencesCount($contentGroups);
        $thinPages = [];

        foreach ($pages as $page) {
            if (($page['status_code'] ?? 0) === 200 && ($page['word_count'] ?? 0) < 300) {
                $thinPages[] = [
                    'url' => $page['url'],
                    'word_count' => (int) $page['word_count'],
                ];
            }
        }

        $canonicalConflicts = $this->canonicalConflicts($pages);
        $orphanUrls = $this->sitemapOrphanUrls($sitemapUrls, $discoveredUrls, $pages, $auditedUrl);
        $warningsCount = 0;

        foreach ([
            $titleGroups,
            $metaDescriptionGroups,
            $h1Groups,
            $contentGroups,
            $thinPages,
            $canonicalConflicts,
            $orphanUrls,
        ] as $warnings) {
            if ($warnings !== []) {
                $warningsCount++;
            }
        }

        return [
            'duplicate_title_groups' => $titleGroups,
            'duplicate_meta_description_groups' => $metaDescriptionGroups,
            'duplicate_h1_groups' => $h1Groups,
            'duplicate_content_groups' => $contentGroups,
            'duplicate_content_count' => $duplicateContentCount,
            'thin_content_pages_count' => count($thinPages),
            'thin_content_pages_sample' => array_slice($thinPages, 0, self::MAX_SITE_QUALITY_SAMPLE),
            'canonical_conflicts_count' => count($canonicalConflicts),
            'canonical_conflicts_sample' => array_slice($canonicalConflicts, 0, self::MAX_SITE_QUALITY_SAMPLE),
            'sitemap_orphan_urls_count' => count($orphanUrls),
            'sitemap_orphan_urls_sample' => array_slice($orphanUrls, 0, self::MAX_SITE_QUALITY_SAMPLE),
            'site_quality_warnings_count' => $warningsCount,
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $pages
     * @return array<int, array{value: string, urls: array<int, string>, count: int}>
     */
    private function duplicateTextGroups(array $pages, string $field): array
    {
        $groups = [];

        foreach ($pages as $page) {
            if (($page['status_code'] ?? 0) !== 200) {
                continue;
            }

            $value = $this->normalizeWhitespace((string) ($page[$field] ?? ''));
            if ($value === '') {
                continue;
            }

            $key = mb_strtolower($value);
            $groups[$key] ??= ['value' => $value, 'urls' => [], 'count' => 0];
            $groups[$key]['count']++;
            if (count($groups[$key]['urls']) < self::MAX_SITE_QUALITY_SAMPLE) {
                $groups[$key]['urls'][] = $page['url'];
            }
        }

        return array_values(array_filter(
            $groups,
            fn (array $group): bool => $group['count'] > 1,
        ));
    }

    /**
     * @param  array<int, array<string, mixed>>  $pages
     * @return array<int, array{fingerprint: string, urls: array<int, string>, count: int}>
     */
    private function duplicateFingerprintGroups(array $pages): array
    {
        $groups = [];

        foreach ($pages as $page) {
            $fingerprint = (string) ($page['content_fingerprint'] ?? '');
            if (($page['status_code'] ?? 0) !== 200 || $fingerprint === '') {
                continue;
            }

            $groups[$fingerprint] ??= ['fingerprint' => $fingerprint, 'urls' => [], 'count' => 0];
            $groups[$fingerprint]['count']++;
            if (count($groups[$fingerprint]['urls']) < self::MAX_SITE_QUALITY_SAMPLE) {
                $groups[$fingerprint]['urls'][] = $page['url'];
            }
        }

        return array_values(array_filter(
            $groups,
            fn (array $group): bool => $group['count'] > 1,
        ));
    }

    /**
     * @param  array<int, array{count: int}>  $groups
     */
    private function duplicateOccurrencesCount(array $groups): int
    {
        return array_sum(array_map(
            fn (array $group): int => max(0, $group['count'] - 1),
            $groups,
        ));
    }

    /**
     * @param  array<int, array<string, mixed>>  $pages
     * @return array<int, array{url: string, canonical_url: string}>
     */
    private function canonicalConflicts(array $pages): array
    {
        $conflicts = [];
        $canonicalGroups = [];

        foreach ($pages as $page) {
            $canonicalUrl = $page['canonical_url'] ?? null;
            if (($page['status_code'] ?? 0) !== 200 || ! is_string($canonicalUrl) || $canonicalUrl === '') {
                continue;
            }

            $pageKey = $this->normalizeUrl($page['url']);
            $canonicalKey = $this->normalizeUrl($canonicalUrl);
            $canonicalGroups[$canonicalKey][] = $page;

            if ($pageKey !== $canonicalKey) {
                $conflicts[$pageKey] = [
                    'url' => $page['url'],
                    'canonical_url' => $canonicalUrl,
                ];
            }
        }

        foreach ($canonicalGroups as $group) {
            if (count($group) < 2) {
                continue;
            }

            foreach ($group as $page) {
                $pageKey = $this->normalizeUrl($page['url']);
                $conflicts[$pageKey] ??= [
                    'url' => $page['url'],
                    'canonical_url' => $page['canonical_url'],
                ];
            }
        }

        return array_values($conflicts);
    }

    /**
     * @param  array<int, string>  $sitemapUrls
     * @param  array<int, string>  $discoveredUrls
     * @param  array<int, array<string, mixed>>  $pages
     * @return array<int, string>
     */
    private function sitemapOrphanUrls(
        array $sitemapUrls,
        array $discoveredUrls,
        array $pages,
        string $auditedUrl,
    ): array {
        $knownUrls = [$this->normalizeUrl($auditedUrl) => true];
        foreach ([...$discoveredUrls, ...array_column($pages, 'url')] as $url) {
            $knownUrls[$this->normalizeUrl($url)] = true;
        }

        $host = strtolower((string) parse_url($auditedUrl, PHP_URL_HOST));
        $orphans = [];
        foreach ($sitemapUrls as $url) {
            if (strtolower((string) parse_url($url, PHP_URL_HOST)) !== $host
                || isset($knownUrls[$this->normalizeUrl($url)])) {
                continue;
            }

            $orphans[$this->normalizeUrl($url)] = $url;
        }

        return array_values($orphans);
    }

    /**
     * @return array{Response, string}
     */
    private function fetchResource(string $url): array
    {
        $currentUrl = $url;
        $redirectCount = 0;

        while (true) {
            $target = $this->urlPolicy->validate($currentUrl);
            $response = $this->httpClient($target)->get($currentUrl);

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
        $structuredData = $this->analyzeStructuredData($xpath, $url);

        return [
            'title' => $title !== '' ? $title : null,
            'title_length' => mb_strlen($title),
            'meta_description' => $description !== '' ? $description : null,
            'meta_description_length' => mb_strlen($description),
            'word_count' => $wordCount,
            'content_fingerprint' => $this->contentFingerprint($visibleText, $wordCount),
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
            ...$structuredData,
            'uses_https' => strtolower((string) parse_url($url, PHP_URL_SCHEME)) === 'https',
        ];
    }

    /**
     * @return array{structured_data_found: bool, structured_data_formats: array<int, string>, json_ld_count: int, microdata_found: bool, rdfa_found: bool, schema_types: array<int, string>, structured_data_errors_count: int, structured_data_errors_sample: array<int, string>, important_schema_types_found: array<int, string>, recommended_schema_types_missing: array<int, string>}
     */
    private function analyzeStructuredData(DOMXPath $xpath, string $url): array
    {
        $formats = [];
        $schemaTypes = [];
        $errors = [];
        $errorsCount = 0;
        $jsonLdBlocks = $xpath->query(
            "//script[translate(normalize-space(@type), 'ABCDEFGHIJKLMNOPQRSTUVWXYZ', 'abcdefghijklmnopqrstuvwxyz') = 'application/ld+json']",
        );

        if ($jsonLdBlocks->length > 0) {
            $formats[] = 'json_ld';
        }

        foreach ($jsonLdBlocks as $index => $block) {
            $json = trim((string) $block->textContent);

            try {
                $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
                if (! is_array($decoded)) {
                    throw new JsonException('The top-level value must be an object or array.');
                }

                $this->collectJsonLdTypes($decoded, $schemaTypes);
            } catch (JsonException $exception) {
                $errorsCount++;
                if (count($errors) < self::MAX_STRUCTURED_DATA_ERRORS_SAMPLE) {
                    $errors[] = 'JSON-LD block '.($index + 1).': '.$exception->getMessage();
                }
            }
        }

        $microdataNodes = $xpath->query('//*[@itemscope or @itemtype or @itemprop]');
        $microdataFound = $microdataNodes->length > 0;
        if ($microdataFound) {
            $formats[] = 'microdata';
            foreach ($xpath->query('//*[@itemtype]') as $node) {
                foreach (preg_split('/\s+/', trim((string) $node->attributes?->getNamedItem('itemtype')?->nodeValue)) ?: [] as $type) {
                    $this->addSchemaType($schemaTypes, $type);
                }
            }
        }

        $rdfaNodes = $xpath->query('//*[@vocab or @typeof or @property]');
        $rdfaFound = $rdfaNodes->length > 0;
        if ($rdfaFound) {
            $formats[] = 'rdfa';
            foreach ($xpath->query('//*[@typeof]') as $node) {
                foreach (preg_split('/\s+/', trim((string) $node->attributes?->getNamedItem('typeof')?->nodeValue)) ?: [] as $type) {
                    $this->addSchemaType($schemaTypes, $type);
                }
            }
        }

        $schemaTypes = array_values($schemaTypes);
        $importantTypes = array_values(array_filter(
            self::IMPORTANT_SCHEMA_TYPES,
            fn (string $type): bool => $this->hasSchemaType($schemaTypes, $type),
        ));
        $recommended = [];

        if ($this->isHomepageLikeUrl($url)) {
            foreach (['Organization', 'WebSite'] as $type) {
                if (! $this->hasSchemaType($schemaTypes, $type)) {
                    $recommended[] = $type;
                }
            }
        }

        if ($this->breadcrumbNavigationFound($xpath)
            && ! $this->hasSchemaType($schemaTypes, 'BreadcrumbList')) {
            $recommended[] = 'BreadcrumbList';
        }

        if ($this->articleLikeContentFound($xpath, $url)
            && ! $this->hasSchemaType($schemaTypes, 'Article')
            && ! $this->hasSchemaType($schemaTypes, 'BlogPosting')) {
            $recommended[] = 'Article';
        }

        return [
            'structured_data_found' => $formats !== [],
            'structured_data_formats' => $formats,
            'json_ld_count' => $jsonLdBlocks->length,
            'microdata_found' => $microdataFound,
            'rdfa_found' => $rdfaFound,
            'schema_types' => $schemaTypes,
            'structured_data_errors_count' => $errorsCount,
            'structured_data_errors_sample' => $errors,
            'important_schema_types_found' => $importantTypes,
            'recommended_schema_types_missing' => array_values(array_unique($recommended)),
        ];
    }

    /**
     * @param  array<string|int, mixed>  $node
     * @param  array<string, string>  $types
     */
    private function collectJsonLdTypes(array $node, array &$types): void
    {
        if (array_key_exists('@type', $node)) {
            foreach ((array) $node['@type'] as $type) {
                if (is_string($type)) {
                    $this->addSchemaType($types, $type);
                }
            }
        }

        foreach ($node as $value) {
            if (is_array($value)) {
                $this->collectJsonLdTypes($value, $types);
            }
        }
    }

    /**
     * @param  array<string, string>  $types
     */
    private function addSchemaType(array &$types, string $type): void
    {
        $type = trim($type);
        if ($type === '') {
            return;
        }

        $normalized = preg_replace('~^.*[/#:](?=[^/#:]+$)~', '', $type) ?? $type;
        foreach (self::IMPORTANT_SCHEMA_TYPES as $importantType) {
            if (strcasecmp($normalized, $importantType) === 0) {
                $normalized = $importantType;
                break;
            }
        }

        $types[strtolower($normalized)] ??= $normalized;
    }

    /**
     * @param  array<int, string>  $types
     */
    private function hasSchemaType(array $types, string $expected): bool
    {
        foreach ($types as $type) {
            if (strcasecmp($type, $expected) === 0) {
                return true;
            }
        }

        return false;
    }

    private function isHomepageLikeUrl(string $url): bool
    {
        $path = strtolower(rtrim((string) parse_url($url, PHP_URL_PATH), '/'));

        return in_array($path, ['', '/index.html', '/index.htm', '/index.php', '/home'], true);
    }

    private function breadcrumbNavigationFound(DOMXPath $xpath): bool
    {
        return $xpath->query(
            "//*[contains(translate(@class, 'ABCDEFGHIJKLMNOPQRSTUVWXYZ', 'abcdefghijklmnopqrstuvwxyz'), 'breadcrumb')"
            ." or contains(translate(@id, 'ABCDEFGHIJKLMNOPQRSTUVWXYZ', 'abcdefghijklmnopqrstuvwxyz'), 'breadcrumb')"
            ." or (self::nav and contains(translate(@aria-label, 'ABCDEFGHIJKLMNOPQRSTUVWXYZ', 'abcdefghijklmnopqrstuvwxyz'), 'breadcrumb'))]",
        )->length > 0;
    }

    private function articleLikeContentFound(DOMXPath $xpath, string $url): bool
    {
        if ($xpath->query('//article')->length > 0) {
            return true;
        }

        $openGraphType = strtolower(trim((string) $xpath->evaluate(
            "string(//meta[translate(@property, 'ABCDEFGHIJKLMNOPQRSTUVWXYZ', 'abcdefghijklmnopqrstuvwxyz') = 'og:type'][1]/@content)",
        )));
        if ($openGraphType === 'article') {
            return true;
        }

        $path = strtolower((string) parse_url($url, PHP_URL_PATH));

        return preg_match('#/(?:blog|articles?|news|posts?)(?:/|$)#', $path) === 1;
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

    private function contentFingerprint(string $visibleText, int $wordCount): ?string
    {
        if ($wordCount < self::MIN_CONTENT_FINGERPRINT_WORDS) {
            return null;
        }

        $normalized = $this->normalizeComparableText($visibleText);

        return $normalized !== '' ? substr(hash('sha256', $normalized), 0, 16) : null;
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
            $target = $this->urlPolicy->validate($currentUrl);
            $response = $this->linkCheckHttpClient($target)->get($currentUrl);

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
            $target = $this->urlPolicy->validate($currentUrl);
            $response = $this->linkCheckHttpClient($target)->get($currentUrl);

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

    /**
     * @return array{content_type: ?string, content_encoding: ?string, compression_enabled: bool, cache_control: ?string, cache_headers_present: bool, server_header: ?string, html_size_kb: float, is_html_response: bool}
     */
    private function performanceMetadata(Response $response, string $body): array
    {
        $contentType = $this->nullableTrimmed((string) $response->header('Content-Type'));
        $contentEncoding = $this->nullableTrimmed((string) $response->header('Content-Encoding'));
        $cacheControl = $this->nullableTrimmed((string) $response->header('Cache-Control'));
        $expires = $this->nullableTrimmed((string) $response->header('Expires'));
        $etag = $this->nullableTrimmed((string) $response->header('ETag'));
        $server = $this->nullableTrimmed((string) $response->header('Server'));
        $isHtml = $contentType !== null
            && preg_match('#^(?:text/html|application/xhtml\+xml)(?:\s*;|$)#i', $contentType) === 1;
        $compressionEnabled = $contentEncoding !== null
            && preg_match('/(?:^|[,\s])(?:gzip|br|deflate)(?:[,\s]|$)/i', $contentEncoding) === 1;

        return [
            'content_type' => $contentType,
            'content_encoding' => $contentEncoding,
            'compression_enabled' => $compressionEnabled,
            'cache_control' => $cacheControl,
            'cache_headers_present' => $cacheControl !== null || $expires !== null || $etag !== null,
            'server_header' => $server,
            'html_size_kb' => round(strlen($body) / 1024, 2),
            'is_html_response' => $isHtml,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function performanceWarningsCount(array $data): int
    {
        $warnings = 0;

        if (($data['response_time_ms'] ?? 0) > 2000) {
            $warnings++;
        }

        if (($data['page_size_bytes'] ?? 0) > 1_000_000) {
            $warnings++;
        }

        if (($data['is_html_response'] ?? false) && ! ($data['compression_enabled'] ?? false)) {
            $warnings++;
        }

        if (! ($data['cache_headers_present'] ?? false)) {
            $warnings++;
        }

        return $warnings;
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
