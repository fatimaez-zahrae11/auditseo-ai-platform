<?php

namespace App\Services\Seo;

use DOMDocument;
use DOMXPath;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class SeoCrawlerService
{
    /**
     * @return array<string, bool|int|string|null>
     */
    public function crawl(string $url): array
    {
        # verifie si l'URL saisie n'est pas dangeureuse ( ex : localhost , private/reserved IPs, & internal hostnames)
        $this->ensureUrlIsSafe($url);

        # le service envoie unr requette HTTP vers le site pr recuperere le code HTML 
        $response = $this->httpClient()->get($url);

        if (! $response->successful()) {
            throw new RuntimeException("The page returned HTTP status {$response->status()}.");
        }
        # le service analyse le code html et extrait les donnees SEO 
        $data = $this->extractSeoData($response->body(), $url);
        $origin = $this->origin($url);
        #  verification de la disponibilite de sitemap.xml et de robots.txt 
        $data['robots_txt_found'] = $this->resourceExists("{$origin}/robots.txt");
        $data['sitemap_xml_found'] = $this->resourceExists("{$origin}/sitemap.xml");
        
        # a la fiin la fct crawl() retourne un tableau comme : (title , meta_dessc... h1_count , images_count , links_count ,robots.txt found , sitemap.xml found ...)
        return $data;
    }

    private function httpClient(): PendingRequest
    {
        return Http::timeout(10)
            ->connectTimeout(5)
            ->withUserAgent('AuditSEO-Crawler/1.0')
            ->withOptions(['allow_redirects' => false]);
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

    # securite dans SeoCrawleService : elle protege contre les attaques SSRF 
    #( a user can try to force my backend to call a internal internet address)
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
        $document = new DOMDocument();
        $previousErrorHandling = libxml_use_internal_errors(true);
        $document->loadHTML($html, LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previousErrorHandling);

        $xpath = new DOMXPath($document);
        $title = trim((string) $xpath->evaluate('string(//title[1])'));
        $description = trim((string) $xpath->evaluate(
            "string(//meta[translate(@name, 'ABCDEFGHIJKLMNOPQRSTUVWXYZ', 'abcdefghijklmnopqrstuvwxyz') = 'description'][1]/@content)",
        ));
        $images = $xpath->query('//img');
        $imagesMissingAlt = $xpath->query('//img[not(@alt) or normalize-space(@alt) = ""]');

        return [
            'title' => $title !== '' ? $title : null,
            'meta_description' => $description !== '' ? $description : null,
            'h1_count' => $xpath->query('//h1')->length,
            'h2_count' => $xpath->query('//h2')->length,
            'images_count' => $images->length,
            'images_missing_alt_count' => $imagesMissingAlt->length,
            'links_count' => $xpath->query('//a[@href]')->length,
            'uses_https' => strtolower((string) parse_url($url, PHP_URL_SCHEME)) === 'https',
        ];
    }
}
