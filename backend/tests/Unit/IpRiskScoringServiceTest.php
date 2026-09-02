<?php

namespace Tests\Unit;

use App\Services\IpRiskScoringService;
use PHPUnit\Framework\TestCase;

class IpRiskScoringServiceTest extends TestCase
{
    public function test_normal_activity_is_low_risk(): void
    {
        $result = (new IpRiskScoringService)->score([
            'request_count' => 12,
            'error_count' => 0,
        ]);

        $this->assertSame([
            'score' => 0,
            'level' => 'low',
            'reason' => 'Normal activity',
        ], $result);
    }

    public function test_logged_scanning_signals_produce_a_deterministic_medium_score(): void
    {
        $signals = [
            'request_count' => 80,
            'error_count' => 8,
            'status_404_count' => 6,
            'distinct_routes_count' => 8,
        ];
        $service = new IpRiskScoringService;

        $first = $service->score($signals);
        $second = $service->score($signals);

        $this->assertSame($first, $second);
        $this->assertSame(26, $first['score']);
        $this->assertSame('medium', $first['level']);
        $this->assertSame('Repeated 404 scanning', $first['reason']);
    }

    public function test_combined_high_volume_logged_failures_are_clamped_to_critical(): void
    {
        $result = (new IpRiskScoringService)->score([
            'request_count' => 1000,
            'error_count' => 600,
            'status_403_count' => 20,
            'status_404_count' => 20,
            'status_429_count' => 6,
            'status_5xx_count' => 10,
            'distinct_routes_count' => 20,
            'admin_denied_count' => 5,
            'auth_failure_count' => 10,
            'recent_burst_count' => 250,
        ]);

        $this->assertSame(100, $result['score']);
        $this->assertSame('critical', $result['level']);
        $this->assertSame('Repeated forbidden admin access', $result['reason']);
    }
}
