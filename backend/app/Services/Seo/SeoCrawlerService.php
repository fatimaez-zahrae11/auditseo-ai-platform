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

    /**
     * @return array<string, bool|int|string|null>
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
        $data['robots_txt_found'] = $this->resourceExists("{$origin}/robots.txt");
        $data['sitemap_xml_found'] = $this->resourceExists("{$origin}/sitemap.xml");

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

    private function resourceExists(string $url): bool
    {
        try {
            return $this->httpClient()->get($url)->successful();
        } catch (ConnectionException) {
            return false;
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
     * @return array<string, bool|int|string|null>
     */
    private function extractSeoData(string $html, string $url): array
    {
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

        return [
            'title' => $title !== '' ? $title : null,
            'meta_description' => $description !== '' ? $description : null,
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
            'images_count' => $images->length,
            'images_missing_alt_count' => $imagesMissingAlt->length,
            'links_count' => $xpath->query('//a[@href]')->length,
            'uses_https' => strtolower((string) parse_url($url, PHP_URL_SCHEME)) === 'https',
        ];
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
