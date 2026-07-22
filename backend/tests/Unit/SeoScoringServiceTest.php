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

    /**
     * @return array<string, bool|int|string|null>
     */
    private function completeData(): array
    {
        return [
            'title' => 'Page',
            'meta_description' => 'Description',
            'h1_count' => 1,
            'h2_count' => 1,
            'images_missing_alt_count' => 0,
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
