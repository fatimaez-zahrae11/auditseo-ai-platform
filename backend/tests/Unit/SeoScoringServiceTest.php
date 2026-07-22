<?php

namespace Tests\Unit;

use App\Services\Seo\SeoScoringService;
use PHPUnit\Framework\TestCase;

class SeoScoringServiceTest extends TestCase
{
    public function test_slow_or_large_pages_reduce_the_performance_score(): void
    {
        $service = new SeoScoringService;
        $baseline = $this->completeData();

        $slowScores = $service->calculate([
            ...$baseline,
            'response_time_ms' => 2501,
        ]);
        $largeScores = $service->calculate([
            ...$baseline,
            'page_size_bytes' => (3 * 1024 * 1024) + 1,
        ]);

        $this->assertSame(80, $slowScores['performance_score']);
        $this->assertSame(80, $largeScores['performance_score']);
    }

    public function test_link_problems_reduce_and_clamp_the_links_score(): void
    {
        $service = new SeoScoringService;

        $scores = $service->calculate([
            ...$this->completeData(),
            'broken_links_count' => 10,
            'empty_anchor_links_count' => 20,
            'generic_anchor_links_count' => 20,
        ]);

        $this->assertSame(10, $scores['links_score']);
        foreach ($scores as $score) {
            $this->assertGreaterThanOrEqual(0, $score);
            $this->assertLessThanOrEqual(100, $score);
        }
    }

    public function test_on_page_content_problems_reduce_and_clamp_the_content_score(): void
    {
        $service = new SeoScoringService;
        $healthyScores = $service->calculate($this->completeData());
        $weakScores = $service->calculate([
            ...$this->completeData(),
            'title_length' => 10,
            'meta_description_length' => 20,
            'word_count' => 20,
            'title_matches_h1' => false,
            'heading_structure' => [
                ['tag' => 'h1', 'text' => 'Main heading'],
                ['tag' => 'h3', 'text' => 'Skipped heading'],
            ],
            'images_alt_missing_ratio' => 0.5,
        ]);

        $this->assertSame(100, $healthyScores['content_score']);
        $this->assertLessThan($healthyScores['content_score'], $weakScores['content_score']);
        foreach ($weakScores as $score) {
            $this->assertGreaterThanOrEqual(0, $score);
            $this->assertLessThanOrEqual(100, $score);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function completeData(): array
    {
        return [
            'title' => 'A descriptive page title for content SEO',
            'title_length' => 40,
            'meta_description' => str_repeat('Useful description ', 5),
            'meta_description_length' => 95,
            'word_count' => 500,
            'h1_count' => 1,
            'h2_count' => 1,
            'title_matches_h1' => true,
            'heading_structure' => [
                ['tag' => 'h1', 'text' => 'A descriptive page title for content SEO'],
                ['tag' => 'h2', 'text' => 'Section'],
            ],
            'images_missing_alt_count' => 0,
            'images_alt_missing_ratio' => 0.0,
            'uses_https' => true,
            'robots_txt_found' => true,
            'sitemap_xml_found' => true,
            'http_status_code' => 200,
            'redirect_count' => 0,
            'canonical_url' => 'https://example.com/page',
            'canonical_matches_final_url' => true,
            'meta_robots' => null,
            'viewport_found' => true,
            'html_lang' => 'en',
            'links_count' => 1,
            'response_time_ms' => 100,
            'page_size_bytes' => 1000,
        ];
    }
}
