<?php

namespace App\Services\Seo;

class SeoScoringService
{
    /**
     * @param array<string, bool|int|string|null> $data
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

        if ($data['links_count'] === 0) {
            $linksScore -= 30;
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
