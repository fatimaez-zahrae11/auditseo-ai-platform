<?php

namespace App\Support;

final class AuditUrl
{
    /**
     * Remove URL components that are not required for an SEO crawl and may
     * contain credentials or other sensitive values.
     */
    public static function canonicalizeForStorage(string $url): string
    {
        $parts = parse_url(trim($url));

        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host'])) {
            return trim($url);
        }

        $scheme = strtolower((string) $parts['scheme']);
        $host = strtolower((string) $parts['host']);

        if (str_contains($host, ':') && ! str_starts_with($host, '[')) {
            $host = '['.$host.']';
        }

        $port = isset($parts['port']) ? (int) $parts['port'] : null;
        $defaultPort = ($scheme === 'http' && $port === 80)
            || ($scheme === 'https' && $port === 443);
        $authority = $host.($port !== null && ! $defaultPort ? ':'.$port : '');
        $path = isset($parts['path']) ? (string) $parts['path'] : '';

        return $scheme.'://'.$authority.$path;
    }
}
