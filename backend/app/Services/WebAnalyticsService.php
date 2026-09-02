<?php

namespace App\Services;

use App\Models\WebAnalyticsEvent;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class WebAnalyticsService
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function recordPageView(array $payload): WebAnalyticsEvent
    {
        return WebAnalyticsEvent::query()->create([
            'visitor_id_hash' => $this->hashIdentifier('visitor', (string) $payload['visitor_id']),
            'session_id_hash' => $this->hashIdentifier('session', (string) $payload['session_id']),
            'user_id' => null,
            'path' => $this->sanitizePath((string) $payload['path']),
            'page_title' => $this->sanitizePageTitle($payload['page_title'] ?? null),
            'referrer_host' => $this->extractReferrerHost($payload['referrer'] ?? null),
            'event_type' => WebAnalyticsEvent::TYPE_PAGE_VIEW,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function aggregate(string $period, string $granularity): array
    {
        $to = CarbonImmutable::now();
        $from = match ($period) {
            '24h' => $to->subHours(23)->startOfHour(),
            '7d' => $to->subDays(6)->startOfDay(),
            default => $to->subDays(29)->startOfDay(),
        };
        $bucketFrom = $granularity === 'hour' ? $from->startOfHour() : $from->startOfDay();
        $bucketExpression = $this->bucketExpression($granularity);
        $series = $this->emptySeries($bucketFrom, $to, $granularity);

        $bucketRows = WebAnalyticsEvent::query()
            ->where('event_type', WebAnalyticsEvent::TYPE_PAGE_VIEW)
            ->whereBetween('created_at', [$from, $to])
            ->selectRaw(
                $bucketExpression.' as period, COUNT(*) as page_views, '.
                'COUNT(DISTINCT visitor_id_hash) as tracked_visitors, '.
                'COUNT(DISTINCT session_id_hash) as sessions',
            )
            ->groupBy(DB::raw($bucketExpression))
            ->get();

        foreach ($bucketRows as $row) {
            $key = $this->periodKey(CarbonImmutable::parse((string) $row->period), $granularity);
            if (! isset($series[$key])) {
                continue;
            }

            $series[$key]['page_views'] = (int) $row->page_views;
            $series[$key]['tracked_visitors'] = (int) $row->tracked_visitors;
            $series[$key]['sessions'] = (int) $row->sessions;
        }

        $bucketSessions = WebAnalyticsEvent::query()
            ->where('event_type', WebAnalyticsEvent::TYPE_PAGE_VIEW)
            ->whereNotNull('session_id_hash')
            ->whereBetween('created_at', [$from, $to])
            ->selectRaw($bucketExpression.' as period, session_id_hash, COUNT(*) as page_views')
            ->groupBy(DB::raw($bucketExpression), 'session_id_hash')
            ->get();
        $this->mergeBounceRates($series, $bucketSessions, $granularity);

        $totalRow = WebAnalyticsEvent::query()
            ->where('event_type', WebAnalyticsEvent::TYPE_PAGE_VIEW)
            ->whereBetween('created_at', [$from, $to])
            ->selectRaw(
                'COUNT(*) as page_views, '.
                'COUNT(DISTINCT visitor_id_hash) as tracked_visitors, '.
                'COUNT(DISTINCT session_id_hash) as sessions',
            )
            ->first();
        $totalSessionCounts = WebAnalyticsEvent::query()
            ->where('event_type', WebAnalyticsEvent::TYPE_PAGE_VIEW)
            ->whereNotNull('session_id_hash')
            ->whereBetween('created_at', [$from, $to])
            ->selectRaw('session_id_hash, COUNT(*) as page_views')
            ->groupBy('session_id_hash')
            ->get();
        $totalSessions = $totalSessionCounts->count();
        $bounceSessions = $totalSessionCounts->filter(
            fn (WebAnalyticsEvent $row): bool => (int) $row->page_views === 1,
        )->count();

        $topPages = WebAnalyticsEvent::query()
            ->where('event_type', WebAnalyticsEvent::TYPE_PAGE_VIEW)
            ->whereBetween('created_at', [$from, $to])
            ->selectRaw(
                'path, COUNT(*) as page_views, '.
                'COUNT(DISTINCT visitor_id_hash) as tracked_visitors, '.
                'COUNT(DISTINCT session_id_hash) as sessions',
            )
            ->groupBy('path')
            ->orderByDesc('page_views')
            ->orderBy('path')
            ->limit(10)
            ->get()
            ->map(fn (WebAnalyticsEvent $row): array => [
                'path' => $row->path,
                'page_views' => (int) $row->page_views,
                'tracked_visitors' => (int) $row->tracked_visitors,
                'sessions' => (int) $row->sessions,
            ])
            ->values()
            ->all();

        return [
            'series' => array_values($series),
            'totals' => [
                'page_views' => (int) ($totalRow?->page_views ?? 0),
                'tracked_visitors' => (int) ($totalRow?->tracked_visitors ?? 0),
                'sessions' => (int) ($totalRow?->sessions ?? 0),
                'bounce_rate' => $this->bounceRate($bounceSessions, $totalSessions),
            ],
            'top_pages' => $topPages,
            'metadata' => [
                'period' => $period,
                'granularity' => $granularity,
                'from' => $from,
                'to' => $to,
                'generated_at' => $to,
                'source' => 'web_analytics_events',
                'bounce_rate_definition' => 'Sessions with exactly one page view divided by all tracked sessions in the selected period.',
            ],
        ];
    }

    public function sanitizePath(string $value): string
    {
        $parsed = parse_url(trim($value));
        $path = is_array($parsed) && isset($parsed['path']) ? (string) $parsed['path'] : '/';
        $path = preg_replace('/[\x00-\x1F\x7F]/u', '', $path) ?? '/';
        $path = str_replace('\\', '/', $path);
        $path = preg_replace('#/+#', '/', $path) ?? '/';
        $path = '/'.ltrim($path, '/');

        return Str::limit($path === '' ? '/' : $path, 512, '');
    }

    private function hashIdentifier(string $scope, string $identifier): string
    {
        return hash_hmac('sha256', $scope.':'.$identifier, (string) config('app.key'));
    }

    private function sanitizePageTitle(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $title = preg_replace('/[\x00-\x1F\x7F]/u', ' ', trim($value)) ?? '';
        $title = preg_replace('/\s+/', ' ', $title) ?? '';

        return $title === '' ? null : Str::limit($title, 200, '');
    }

    private function extractReferrerHost(mixed $value): ?string
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        $host = parse_url($value, PHP_URL_HOST);
        if (! is_string($host) || $host === '') {
            return null;
        }

        return Str::limit(Str::lower($host), 253, '');
    }

    private function bucketExpression(string $granularity): string
    {
        if (DB::getDriverName() === 'sqlite') {
            return $granularity === 'hour'
                ? "strftime('%Y-%m-%d %H:00:00', created_at)"
                : "strftime('%Y-%m-%d', created_at)";
        }

        return $granularity === 'hour'
            ? "date_trunc('hour', created_at)"
            : "date_trunc('day', created_at)";
    }

    /**
     * @return array<string, array{period: string, page_views: int, tracked_visitors: int, sessions: int, bounce_rate: float|null}>
     */
    private function emptySeries(
        CarbonImmutable $from,
        CarbonImmutable $to,
        string $granularity,
    ): array {
        $series = [];
        $cursor = $from;

        while ($cursor <= $to) {
            $key = $this->periodKey($cursor, $granularity);
            $series[$key] = [
                'period' => $key,
                'page_views' => 0,
                'tracked_visitors' => 0,
                'sessions' => 0,
                'bounce_rate' => null,
            ];
            $cursor = $granularity === 'hour' ? $cursor->addHour() : $cursor->addDay();
        }

        return $series;
    }

    /**
     * @param  array<string, array{period: string, page_views: int, tracked_visitors: int, sessions: int, bounce_rate: float|null}>  $series
     * @param  Collection<int, WebAnalyticsEvent>  $sessionRows
     */
    private function mergeBounceRates(array &$series, Collection $sessionRows, string $granularity): void
    {
        $byPeriod = $sessionRows->groupBy(fn (WebAnalyticsEvent $row): string => $this->periodKey(
            CarbonImmutable::parse((string) $row->period),
            $granularity,
        ));

        foreach ($byPeriod as $key => $rows) {
            if (! isset($series[$key])) {
                continue;
            }

            $series[$key]['bounce_rate'] = $this->bounceRate(
                $rows->filter(fn (WebAnalyticsEvent $row): bool => (int) $row->page_views === 1)->count(),
                $rows->count(),
            );
        }
    }

    private function bounceRate(int $bounceSessions, int $totalSessions): ?float
    {
        return $totalSessions === 0 ? null : round(($bounceSessions / $totalSessions) * 100, 2);
    }

    private function periodKey(CarbonImmutable $period, string $granularity): string
    {
        return $granularity === 'hour'
            ? $period->startOfHour()->toIso8601String()
            : $period->format('Y-m-d');
    }
}
