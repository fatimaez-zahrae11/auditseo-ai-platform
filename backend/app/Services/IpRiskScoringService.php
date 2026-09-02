<?php

namespace App\Services;

final class IpRiskScoringService
{
    /**
     * Deterministic score derived only from recorded access-log counters.
     * Volume contributes at most 30 points, error rate 25, 403s 20, 404
     * scanning 15, rate limits 15, 5xx responses 10, denied admin access
     * 20, failed auth access 10, and a five-minute burst 20. The result is
     * clamped to 100 and classified as critical >= 70, high >= 45,
     * medium >= 20, otherwise low.
     *
     * @param  array<string, int>  $signals
     * @return array{score: int, level: string, reason: string}
     */
    public function score(array $signals): array
    {
        $requests = max(0, $signals['request_count'] ?? 0);
        $errors = max(0, $signals['error_count'] ?? 0);
        $status403 = max(0, $signals['status_403_count'] ?? 0);
        $status404 = max(0, $signals['status_404_count'] ?? 0);
        $status429 = max(0, $signals['status_429_count'] ?? 0);
        $status5xx = max(0, $signals['status_5xx_count'] ?? 0);
        $adminDenied = max(0, $signals['admin_denied_count'] ?? 0);
        $authFailures = max(0, $signals['auth_failure_count'] ?? 0);
        $distinctRoutes = max(0, $signals['distinct_routes_count'] ?? 0);
        $burst = max(0, $signals['recent_burst_count'] ?? 0);
        $errorRate = $requests === 0 ? 0.0 : $errors / $requests;
        $score = match (true) {
            $requests >= 1000 => 30,
            $requests >= 250 => 20,
            $requests >= 75 => 10,
            $requests >= 25 => 5,
            default => 0,
        };
        $score += match (true) {
            $errors >= 20 && $errorRate >= 0.5 => 25,
            $errors >= 10 && $errorRate >= 0.25 => 18,
            $errors >= 3 && $errorRate >= 0.1 => 8,
            $errors > 0 => 3,
            default => 0,
        };
        $score += match (true) {
            $status403 >= 10 => 20,
            $status403 >= 3 => 12,
            $status403 > 0 => 4,
            default => 0,
        };
        $score += match (true) {
            $status404 >= 20 && $distinctRoutes >= 10 => 15,
            $status404 >= 5 && $distinctRoutes >= 5 => 8,
            default => 0,
        };
        $score += match (true) {
            $status429 >= 5 => 15,
            $status429 > 0 => 8,
            default => 0,
        };
        $score += match (true) {
            $status5xx >= 10 => 10,
            $status5xx > 0 => 4,
            default => 0,
        };
        $score += match (true) {
            $adminDenied >= 5 => 20,
            $adminDenied > 0 => 8,
            default => 0,
        };
        $score += match (true) {
            $authFailures >= 10 => 10,
            $authFailures >= 3 => 5,
            default => 0,
        };
        $score += match (true) {
            $burst >= 250 => 20,
            $burst >= 100 => 12,
            $burst >= 50 => 6,
            default => 0,
        };
        $score = min(100, $score);
        $level = match (true) {
            $score >= 70 => 'critical',
            $score >= 45 => 'high',
            $score >= 20 => 'medium',
            default => 'low',
        };

        return [
            'score' => $score,
            'level' => $level,
            'reason' => $this->reason(
                $requests,
                $errors,
                $errorRate,
                $status403,
                $status404,
                $status429,
                $status5xx,
                $adminDenied,
                $authFailures,
                $distinctRoutes,
                $burst,
            ),
        ];
    }

    private function reason(
        int $requests,
        int $errors,
        float $errorRate,
        int $status403,
        int $status404,
        int $status429,
        int $status5xx,
        int $adminDenied,
        int $authFailures,
        int $distinctRoutes,
        int $burst,
    ): string {
        return match (true) {
            $adminDenied >= 5 => 'Repeated forbidden admin access',
            $status403 >= 3 && $errorRate >= 0.15 => 'High 403 rate',
            $status404 >= 5 && $distinctRoutes >= 5 => 'Repeated 404 scanning',
            $status429 > 0 => 'Rate limit responses detected',
            $authFailures >= 3 => 'Repeated authentication failures',
            $burst >= 50 => 'High request burst',
            $status5xx > 0 => 'Server error responses detected',
            $requests >= 75 => 'High request volume',
            $errors >= 3 && $errorRate >= 0.1 => 'Elevated HTTP error rate',
            default => 'Normal activity',
        };
    }
}
