<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\IndexAdminIpIntelligenceRequest;
use App\Models\AccessLog;
use App\Services\IpGeolocationService;
use App\Services\IpRiskScoringService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

class AdminSecurityController extends Controller
{
    private const DEFAULT_PER_PAGE = 20;

    public function ipIntelligence(
        IndexAdminIpIntelligenceRequest $request,
        IpGeolocationService $geolocationService,
        IpRiskScoringService $riskScoringService,
    ): JsonResponse {
        $filters = $request->validated();
        $period = $filters['period'] ?? '24h';
        $to = CarbonImmutable::now();
        $from = match ($period) {
            '7d' => $to->subDays(7),
            '30d' => $to->subDays(30),
            default => $to->subDay(),
        };
        $burstSince = $to->subMinutes(5)->max($from);

        $aggregates = AccessLog::query()
            ->whereNotNull('ip_address')
            ->whereBetween('created_at', [$from, $to])
            ->when(
                isset($filters['user_id']),
                fn (Builder $query) => $query->where('user_id', $filters['user_id']),
            )
            ->select('ip_address')
            ->selectRaw(
                "COUNT(*) as request_count,
                SUM(CASE WHEN status_code >= 400 THEN 1 ELSE 0 END) as error_count,
                SUM(CASE WHEN status_code = 401 THEN 1 ELSE 0 END) as status_401_count,
                SUM(CASE WHEN status_code = 403 THEN 1 ELSE 0 END) as status_403_count,
                SUM(CASE WHEN status_code = 404 THEN 1 ELSE 0 END) as status_404_count,
                SUM(CASE WHEN status_code = 429 THEN 1 ELSE 0 END) as status_429_count,
                SUM(CASE WHEN status_code >= 500 THEN 1 ELSE 0 END) as status_5xx_count,
                COUNT(DISTINCT route) as distinct_routes_count,
                COUNT(DISTINCT user_id) as distinct_users_count,
                SUM(CASE WHEN route LIKE '/api/admin/%' THEN 1 ELSE 0 END) as admin_route_attempts_count,
                SUM(CASE WHEN route LIKE '/api/admin/%' AND status_code IN (401, 403) THEN 1 ELSE 0 END) as admin_denied_count,
                SUM(CASE WHEN route IN ('/api/login', '/api/register', '/api/email/verification-notification') THEN 1 ELSE 0 END) as auth_route_attempts_count,
                SUM(CASE WHEN route IN ('/api/login', '/api/register', '/api/email/verification-notification') AND status_code >= 400 THEN 1 ELSE 0 END) as auth_failure_count,
                SUM(CASE WHEN created_at >= ? THEN 1 ELSE 0 END) as recent_burst_count,
                MAX(created_at) as last_seen_at",
                [$burstSince],
            )
            ->groupBy('ip_address')
            ->get();

        $rawAddresses = $aggregates->pluck('ip_address')->map(fn (mixed $ip): string => (string) $ip)->all();
        $locations = $geolocationService->resolveMany($rawAddresses);
        $usersByAddress = $this->usersByAddress($rawAddresses, $from, $to, $filters['user_id'] ?? null);
        $records = $aggregates
            ->map(function (AccessLog $aggregate) use (
                $locations,
                $usersByAddress,
                $riskScoringService,
                $geolocationService,
            ): array {
                $ipAddress = (string) $aggregate->ip_address;
                $signals = [
                    'request_count' => (int) $aggregate->request_count,
                    'error_count' => (int) $aggregate->error_count,
                    'status_401_count' => (int) $aggregate->status_401_count,
                    'status_403_count' => (int) $aggregate->status_403_count,
                    'status_404_count' => (int) $aggregate->status_404_count,
                    'status_429_count' => (int) $aggregate->status_429_count,
                    'status_5xx_count' => (int) $aggregate->status_5xx_count,
                    'distinct_routes_count' => (int) $aggregate->distinct_routes_count,
                    'distinct_users_count' => (int) $aggregate->distinct_users_count,
                    'admin_route_attempts_count' => (int) $aggregate->admin_route_attempts_count,
                    'admin_denied_count' => (int) $aggregate->admin_denied_count,
                    'auth_route_attempts_count' => (int) $aggregate->auth_route_attempts_count,
                    'auth_failure_count' => (int) $aggregate->auth_failure_count,
                    'recent_burst_count' => (int) $aggregate->recent_burst_count,
                ];
                $risk = $riskScoringService->score($signals);
                $location = $locations[$ipAddress] ?? [
                    'ip_masked' => $geolocationService->mask($ipAddress),
                    'country_code' => null,
                    'country_name' => 'Unknown',
                    'region' => null,
                    'city' => null,
                    'latitude' => null,
                    'longitude' => null,
                ];

                return [
                    'ip_masked' => $location['ip_masked'],
                    'country_code' => $location['country_code'],
                    'country_name' => $location['country_name'],
                    'region' => $location['region'],
                    'city' => $location['city'],
                    'latitude' => $location['latitude'],
                    'longitude' => $location['longitude'],
                    ...$signals,
                    'users' => $usersByAddress[$ipAddress] ?? [],
                    'risk_level' => $risk['level'],
                    'risk_score' => $risk['score'],
                    'risk_reason' => $risk['reason'],
                    'last_seen_at' => CarbonImmutable::parse((string) $aggregate->last_seen_at),
                ];
            })
            ->filter(fn (array $record): bool => $this->matchesFilters($record, $filters))
            ->sortBy([
                ['risk_score', 'desc'],
                ['request_count', 'desc'],
                ['last_seen_at', 'desc'],
            ])
            ->values();

        $perPage = (int) ($filters['per_page'] ?? self::DEFAULT_PER_PAGE);
        $page = (int) ($filters['page'] ?? 1);
        $paginator = new LengthAwarePaginator(
            $records->slice(($page - 1) * $perPage, $perPage)->values()->all(),
            $records->count(),
            $perPage,
            $page,
            ['path' => $request->url(), 'query' => $request->query()],
        );

        return response()->json([
            'summary' => $this->summary($records),
            'top_addresses_heatmap' => $this->heatmap($records),
            'map_points' => $this->mapPoints($records),
            'external_exposure' => $this->externalExposure($records),
            'results' => $paginator->items(),
            'pagination' => $this->pagination($paginator),
            'metadata' => [
                'period' => $period,
                'from' => $from,
                'to' => $to,
                'generated_at' => $to,
                'ip_display' => 'masked',
                'geolocation' => 'Cached MaxMind GeoLite2 data when configured; otherwise Local network or Unknown.',
            ],
        ]);
    }

    /**
     * @param  list<string>  $addresses
     * @return array<string, list<array{id: int, email: string}>>
     */
    private function usersByAddress(
        array $addresses,
        CarbonImmutable $from,
        CarbonImmutable $to,
        mixed $userId,
    ): array {
        if ($addresses === []) {
            return [];
        }

        return AccessLog::query()
            ->join('users', 'users.id', '=', 'access_logs.user_id')
            ->whereIn('access_logs.ip_address', $addresses)
            ->whereBetween('access_logs.created_at', [$from, $to])
            ->when($userId !== null, fn ($query) => $query->where('users.id', $userId))
            ->select(['access_logs.ip_address', 'users.id', 'users.email'])
            ->distinct()
            ->get()
            ->groupBy('ip_address')
            ->map(fn (Collection $users): array => $users
                ->take(10)
                ->map(fn ($user): array => [
                    'id' => (int) $user->id,
                    'email' => (string) $user->email,
                ])
                ->values()
                ->all())
            ->all();
    }

    /** @param array<string, mixed> $record @param array<string, mixed> $filters */
    private function matchesFilters(array $record, array $filters): bool
    {
        if (isset($filters['risk']) && $record['risk_level'] !== $filters['risk']) {
            return false;
        }

        if (! isset($filters['country'])) {
            return true;
        }

        $country = mb_strtolower(trim($filters['country']));

        return mb_strtolower((string) $record['country_code']) === $country
            || mb_strtolower((string) $record['country_name']) === $country;
    }

    /** @param Collection<int, array<string, mixed>> $records */
    private function summary(Collection $records): array
    {
        $countryKeys = $records
            ->filter(fn (array $record): bool => ! in_array($record['country_name'], ['Unknown', 'Local network'], true))
            ->map(fn (array $record): string => (string) ($record['country_code'] ?: $record['country_name']))
            ->filter()
            ->unique();

        return [
            'critical' => $records->where('risk_level', 'critical')->count(),
            'high' => $records->where('risk_level', 'high')->count(),
            'medium' => $records->where('risk_level', 'medium')->count(),
            'low' => $records->where('risk_level', 'low')->count(),
            'unique_ips' => $records->count(),
            'countries_count' => $countryKeys->count(),
            'requests_count' => (int) $records->sum('request_count'),
            'errors_count' => (int) $records->sum('error_count'),
        ];
    }

    /** @param Collection<int, array<string, mixed>> $records */
    private function heatmap(Collection $records): array
    {
        return $records->take(16)->map(fn (array $record): array => [
            'ip_masked' => $record['ip_masked'],
            'label' => $record['ip_masked'],
            'request_count' => $record['request_count'],
            'error_count' => $record['error_count'],
            'risk_score' => $record['risk_score'],
            'risk_level' => $record['risk_level'],
        ])->values()->all();
    }

    /** @param Collection<int, array<string, mixed>> $records */
    private function mapPoints(Collection $records): array
    {
        return $records
            ->filter(fn (array $record): bool => $record['latitude'] !== null && $record['longitude'] !== null)
            ->groupBy(fn (array $record): string => implode('|', [
                $record['latitude'],
                $record['longitude'],
                $record['country_code'],
                $record['city'],
            ]))
            ->map(function (Collection $locations): array {
                $first = $locations->first();
                $highestRisk = $locations->sortByDesc('risk_score')->first();

                return [
                    'country_code' => $first['country_code'],
                    'country_name' => $first['country_name'],
                    'city' => $first['city'],
                    'latitude' => $first['latitude'],
                    'longitude' => $first['longitude'],
                    'request_count' => (int) $locations->sum('request_count'),
                    'error_count' => (int) $locations->sum('error_count'),
                    'risk_level' => $highestRisk['risk_level'],
                ];
            })
            ->values()
            ->all();
    }

    /** @param Collection<int, array<string, mixed>> $records */
    private function externalExposure(Collection $records): array
    {
        return $records
            ->whereIn('risk_level', ['critical', 'high', 'medium'])
            ->take(8)
            ->map(fn (array $record): array => [
                'title' => $this->exposureTitle($record['risk_reason']),
                'ip_masked' => $record['ip_masked'],
                'risk_level' => $record['risk_level'],
                'reason' => $record['risk_reason'],
                'request_count' => $record['request_count'],
                'last_seen_at' => $record['last_seen_at'],
            ])
            ->values()
            ->all();
    }

    private function exposureTitle(string $reason): string
    {
        return match ($reason) {
            'Repeated forbidden admin access' => 'Forbidden admin route activity',
            'Repeated 404 scanning' => 'Repeated missing-route activity',
            'Rate limit responses detected' => 'Rate limit activity detected',
            'Repeated authentication failures' => 'Authentication failures detected',
            'High request burst' => 'Request burst detected',
            default => 'Elevated platform request activity',
        };
    }

    /** @return array<string, int|string|null> */
    private function pagination(LengthAwarePaginator $paginator): array
    {
        return [
            'current_page' => $paginator->currentPage(),
            'last_page' => $paginator->lastPage(),
            'per_page' => $paginator->perPage(),
            'total' => $paginator->total(),
            'from' => $paginator->firstItem(),
            'to' => $paginator->lastItem(),
            'previous_page_url' => $paginator->previousPageUrl(),
            'next_page_url' => $paginator->nextPageUrl(),
        ];
    }
}
