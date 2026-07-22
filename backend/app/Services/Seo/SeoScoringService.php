<?php

namespace App\Services\Seo;

class SeoScoringService
{
    /**
     * @param  array<string, mixed>  $data
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
        } elseif (($data['title_length'] ?? mb_strlen($data['title'])) < 30
            || ($data['title_length'] ?? mb_strlen($data['title'])) > 60) {
            $contentScore -= 5;
        }

        if ($data['meta_description'] === null) {
            $contentScore -= 20;
        } elseif (($data['meta_description_length'] ?? mb_strlen($data['meta_description'])) < 70
            || ($data['meta_description_length'] ?? mb_strlen($data['meta_description'])) > 160) {
            $contentScore -= 5;
        }

        if (array_key_exists('word_count', $data) && $data['word_count'] < 300) {
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

        if ($data['title'] !== null
            && $data['h1_count'] > 0
            && array_key_exists('title_matches_h1', $data)
            && ! $data['title_matches_h1']) {
            $contentScore -= 5;
        }

        if ($this->headingStructureSkipsLevels($data['heading_structure'] ?? [])) {
            $contentScore -= 5;
        }

        $contentScore -= min(25, ((int) $data['images_missing_alt_count']) * 5);

        if (($data['images_alt_missing_ratio'] ?? 0) > 0.3) {
            $contentScore -= 10;
        }

        $contentScore -= min(20, ((int) ($data['pages_with_missing_title_count'] ?? 0)) * 10);
        $contentScore -= min(20, ((int) ($data['pages_with_missing_meta_description_count'] ?? 0)) * 8);
        $contentScore -= min(20, ((int) ($data['pages_with_missing_h1_count'] ?? 0)) * 10);
        $contentScore -= min(15, ((int) ($data['pages_with_low_word_count_count'] ?? 0)) * 5);
        $duplicateTitles = max(
            (int) ($data['duplicate_titles_count'] ?? 0),
            $this->groupDuplicateOccurrences($data['duplicate_title_groups'] ?? []),
        );
        $duplicateMetaDescriptions = max(
            (int) ($data['duplicate_meta_descriptions_count'] ?? 0),
            $this->groupDuplicateOccurrences($data['duplicate_meta_description_groups'] ?? []),
        );
        $duplicateH1s = max(
            (int) ($data['duplicate_h1_count'] ?? 0),
            $this->groupDuplicateOccurrences($data['duplicate_h1_groups'] ?? []),
        );
        $contentScore -= min(20, $duplicateTitles * 10);
        $contentScore -= min(20, $duplicateMetaDescriptions * 8);
        $contentScore -= min(10, $duplicateH1s * 5);
        $contentScore -= min(25, ((int) ($data['duplicate_content_count'] ?? 0)) * 10);

        $representedThinPages = (int) ($data['pages_with_low_word_count_count'] ?? 0);
        if (array_key_exists('word_count', $data) && $data['word_count'] < 300) {
            $representedThinPages++;
        }
        $additionalThinPages = max(
            0,
            (int) ($data['thin_content_pages_count'] ?? 0) - $representedThinPages,
        );
        $contentScore -= min(20, $additionalThinPages * 5);

        if (! $data['uses_https']) {
            $technicalScore -= 40;
        }

        if (! $data['robots_txt_found']) {
            $technicalScore -= 20;
        }

        if (! $data['sitemap_xml_found']) {
            $technicalScore -= 20;
        }

        if (($data['robots_txt_allows_audited_url'] ?? true) === false) {
            $technicalScore -= 40;
        }

        if (($data['sitemap_xml_found'] ?? false) && ! ($data['sitemap_xml_is_valid'] ?? false)) {
            $technicalScore -= 20;
        }

        if (($data['sitemap_non_https_urls_count'] ?? 0) > 0) {
            $technicalScore -= 15;
        }

        $technicalScore -= min(30, ((int) ($data['sitemap_broken_urls_count'] ?? 0)) * 10);
        $technicalScore -= min(30, ((int) ($data['pages_with_http_errors_count'] ?? 0)) * 10);
        $technicalScore -= min(30, ((int) ($data['pages_with_noindex_count'] ?? 0)) * 10);
        $technicalScore -= min(30, ((int) ($data['canonical_conflicts_count'] ?? 0)) * 10);

        if (($data['http_status_code'] ?? 200) !== 200) {
            $technicalScore -= 50;
        }

        if (($data['redirect_count'] ?? 0) > 1) {
            $technicalScore -= 10;
        }

        if (array_key_exists('is_html_response', $data) && ! $data['is_html_response']) {
            $technicalScore -= 20;
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

        if (array_key_exists('structured_data_found', $data) && ! $data['structured_data_found']) {
            $technicalScore -= 5;
        }

        $technicalScore -= min(30, ((int) ($data['structured_data_errors_count'] ?? 0)) * 15);

        if ($data['links_count'] === 0) {
            $linksScore -= 30;
        }

        $linksScore -= min(60, ((int) ($data['broken_links_count'] ?? 0)) * 15);
        $linksScore -= min(15, ((int) ($data['empty_anchor_links_count'] ?? 0)) * 3);
        $linksScore -= min(15, ((int) ($data['generic_anchor_links_count'] ?? 0)) * 3);

        if (($data['response_time_ms'] ?? 0) > 5000) {
            $performanceScore -= 45;
        } elseif (($data['response_time_ms'] ?? 0) > 2000) {
            $performanceScore -= 20;
        } elseif (($data['response_time_ms'] ?? 0) > 1000) {
            $performanceScore -= 10;
        }

        if (($data['page_size_bytes'] ?? 0) > 3_000_000) {
            $performanceScore -= 40;
        } elseif (($data['page_size_bytes'] ?? 0) > 1_000_000) {
            $performanceScore -= 20;
        }

        if (($data['is_html_response'] ?? false) && ! ($data['compression_enabled'] ?? false)) {
            $performanceScore -= 20;
        }

        if (array_key_exists('cache_headers_present', $data) && ! $data['cache_headers_present']) {
            $performanceScore -= 5;
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

    /**
     * @param  array<int, array{count?: int}>  $groups
     */
    private function groupDuplicateOccurrences(array $groups): int
    {
        return array_sum(array_map(
            fn (array $group): int => max(0, ((int) ($group['count'] ?? 0)) - 1),
            $groups,
        ));
    }

    /**
     * @param  array<int, array{tag: string, text: string}>  $headings
     */
    private function headingStructureSkipsLevels(array $headings): bool
    {
        $previousLevel = null;

        foreach ($headings as $heading) {
            $level = (int) substr($heading['tag'], 1);
            if ($previousLevel !== null && $level > $previousLevel + 1) {
                return true;
            }

            $previousLevel = $level;
        }

        return false;
    }
}
