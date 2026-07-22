<?php

namespace App\Services\Seo;

class SeoScoringService
{
    /**
     * @param  array<string, bool|int|string|null>  $data
     * @return array{global_score: int, technical_score: int, content_score: int, links_score: int, performance_score: int}
     */
    public function calculate(array $data): array
    {
        $technicalScore = 100;
        $contentScore = 100;
        $linksScore = 100;
        $performanceScore = 100;

        if ($data['title'] === null) {
            $contentScore -= 25;
        }

        if ($data['meta_description'] === null) {
            $contentScore -= 20;
        }

        if ($data['h1_count'] === 0) {
            $contentScore -= 20;
        } elseif ($data['h1_count'] > 1) {
            $contentScore -= 10;
        }

        if (($data['h2_count'] ?? 1) === 0) {
            $contentScore -= 5;
        }

        $contentScore -= min(25, ((int) $data['images_missing_alt_count']) * 5);

        if (! $data['uses_https']) {
            $technicalScore -= 40;
        }

        if (! $data['robots_txt_found']) {
            $technicalScore -= 20;
        }

        if (! $data['sitemap_xml_found']) {
            $technicalScore -= 20;
        }

        if (($data['http_status_code'] ?? 200) !== 200) {
            $technicalScore -= 50;
        }

        if (($data['redirect_count'] ?? 0) > 1) {
            $technicalScore -= 10;
        }

        if (array_key_exists('canonical_url', $data) && $data['canonical_url'] === null) {
            $technicalScore -= 10;
        } elseif (isset($data['canonical_url']) && ! ($data['canonical_matches_final_url'] ?? false)) {
            $technicalScore -= 10;
        }

        $metaRobots = strtolower((string) ($data['meta_robots'] ?? ''));
        if (preg_match('/(?:^|[\s,])noindex(?:[\s,]|$)/', $metaRobots)) {
            $technicalScore -= 30;
        }
        if (preg_match('/(?:^|[\s,])nofollow(?:[\s,]|$)/', $metaRobots)) {
            $technicalScore -= 10;
        }

        if (! ($data['viewport_found'] ?? true)) {
            $technicalScore -= 10;
        }

        if (array_key_exists('html_lang', $data) && $data['html_lang'] === null) {
            $technicalScore -= 5;
        }

        if ($data['links_count'] === 0) {
            $linksScore -= 30;
        }

        if (($data['response_time_ms'] ?? 0) > 2000) {
            $performanceScore -= 20;
        } elseif (($data['response_time_ms'] ?? 0) > 1000) {
            $performanceScore -= 10;
        }

        if (($data['page_size_bytes'] ?? 0) > 3 * 1024 * 1024) {
            $performanceScore -= 20;
        } elseif (($data['page_size_bytes'] ?? 0) > 1024 * 1024) {
            $performanceScore -= 10;
        }

        $technicalScore = $this->clamp($technicalScore);
        $contentScore = $this->clamp($contentScore);
        $linksScore = $this->clamp($linksScore);
        $performanceScore = $this->clamp($performanceScore);
        $globalScore = (int) round(
            ($technicalScore + $contentScore + $linksScore + $performanceScore) / 4,
        );

        return [
            'global_score' => $this->clamp($globalScore),
            'technical_score' => $technicalScore,
            'content_score' => $contentScore,
            'links_score' => $linksScore,
            'performance_score' => $performanceScore,
        ];
    }

    private function clamp(int $score): int
    {
        return max(0, min(100, $score));
    }
}
