<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\IndexAdminActiveUsersRequest;
use App\Http\Requests\Admin\IndexAdminHeavyUsersRequest;
use App\Http\Requests\Admin\IndexAdminTrafficRequest;
use App\Http\Requests\Admin\IndexAdminWebTrafficRequest;
use App\Models\AccessLog;
use App\Models\AiRecommendation;
use App\Models\Audit;
use App\Models\User;
use App\Services\WebAnalyticsService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class AdminAnalyticsController extends Controller
{
    private const ACTIVE_WINDOW_MINUTES = 15;

    private const DEFAULT_PER_PAGE = 20;

    private const MAX_PER_PAGE = 100;

    private const HEAVY_USER_AUDIT_WEIGHT = 10;

    private const HEAVY_USER_RECOMMENDATION_WEIGHT = 8;

    private const HEAVY_USER_ERROR_WEIGHT = 2;

    public function overview(): JsonResponse
    {
        $generatedAt = CarbonImmutable::now();
        $activeSince = $generatedAt->subMinutes(self::ACTIVE_WINDOW_MINUTES);
        $users = User::query()
            ->selectRaw(
                'COUNT(*) as total_users, '.
                'SUM(CASE WHEN is_active THEN 1 ELSE 0 END) as active_accounts, '.
                'SUM(CASE WHEN NOT is_active THEN 1 ELSE 0 END) as inactive_accounts, '.
                'SUM(CASE WHEN role = ? THEN 1 ELSE 0 END) as admin_users',
                [User::ROLE_ADMIN],
            )
            ->first();
        $audits = Audit::query()
            ->selectRaw(
                'COUNT(*) as total_audits, '.
                'SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as pending_audits, '.
                'SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as running_audits, '.
                'SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as completed_audits, '.
                'SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as failed_audits',
                [
                    Audit::STATUS_PENDING,
                    Audit::STATUS_RUNNING,
                    Audit::STATUS_COMPLETED,
                    Audit::STATUS_FAILED,
                ],
            )
            ->first();
        $requests = AccessLog::query()
            ->selectRaw(
                'COUNT(DISTINCT CASE WHEN user_id IS NOT NULL AND created_at >= ? '.
                'THEN user_id END) as active_users, '.
                'SUM(CASE WHEN created_at >= ? THEN 1 ELSE 0 END) as last_24h, '.
                'SUM(CASE WHEN created_at >= ? THEN 1 ELSE 0 END) as last_7d',
                [$activeSince, $generatedAt->subDay(), $generatedAt->subDays(7)],
            )
            ->first();

        // Global analytics are safe only behind the auth:sanctum, active, and admin route group.
        return response()->json([
            'total_users' => (int) ($users?->total_users ?? 0),
            'active_users' => (int) ($requests?->active_users ?? 0),
            'inactive_users' => (int) ($users?->inactive_accounts ?? 0),
            'admin_users' => (int) ($users?->admin_users ?? 0),
            'total_audits' => (int) ($audits?->total_audits ?? 0),
            'pending_audits' => (int) ($audits?->pending_audits ?? 0),
            'running_audits' => (int) ($audits?->running_audits ?? 0),
            'completed_audits' => (int) ($audits?->completed_audits ?? 0),
            'failed_audits' => (int) ($audits?->failed_audits ?? 0),
            'total_recommendations' => AiRecommendation::query()->count(),
            'requests_last_24h' => (int) ($requests?->last_24h ?? 0),
            'requests_last_7d' => (int) ($requests?->last_7d ?? 0),
            'generated_at' => $generatedAt,
            'metadata' => [
                'active_users_window_minutes' => self::ACTIVE_WINDOW_MINUTES,
                'active_users_definition' => $this->activeUsersDefinition(),
            ],
        ]);
    }

    public function traffic(IndexAdminTrafficRequest $request): JsonResponse
    {
        $filters = $request->validated();
        $period = $filters['period'] ?? '7d';
        $granularity = $filters['granularity']
            ?? ($period === '24h' ? 'hour' : 'day');
        $to = CarbonImmutable::now();
        $from = match ($period) {
            '24h' => $to->subHours(23)->startOfHour(),
            '30d' => $to->subDays(29)->startOfDay(),
            default => $to->subDays(6)->startOfDay(),
        };
        $bucketExpression = $this->trafficBucketExpression($granularity);
        $series = $this->emptyTrafficSeries($from, $to, $granularity);

        $accessRows = AccessLog::query()
            ->whereBetween('created_at', [$from, $to])
            ->selectRaw(
                $bucketExpression.' as period, COUNT(*) as requests, '.
                'SUM(CASE WHEN status_code >= 400 THEN 1 ELSE 0 END) as http_errors',
            )
            ->groupBy(DB::raw($bucketExpression))
            ->get();
        $auditRows = Audit::query()
            ->whereBetween('created_at', [$from, $to])
            ->selectRaw($bucketExpression.' as period, COUNT(*) as audits')
            ->groupBy(DB::raw($bucketExpression))
            ->get();
        $recommendationRows = AiRecommendation::query()
            ->whereBetween('created_at', [$from, $to])
            ->selectRaw($bucketExpression.' as period, COUNT(*) as recommendations')
            ->groupBy(DB::raw($bucketExpression))
            ->get();

        $this->mergeTrafficRows($series, $accessRows, $granularity, ['requests', 'http_errors']);
        $this->mergeTrafficRows($series, $auditRows, $granularity, ['audits']);
        $this->mergeTrafficRows($series, $recommendationRows, $granularity, ['recommendations']);

        $points = array_values($series);
        $totals = array_reduce(
            $points,
            fn (array $totals, array $point): array => [
                'requests' => $totals['requests'] + $point['requests'],
                'audits' => $totals['audits'] + $point['audits'],
                'recommendations' => $totals['recommendations'] + $point['recommendations'],
                'http_errors' => $totals['http_errors'] + $point['http_errors'],
            ],
            ['requests' => 0, 'audits' => 0, 'recommendations' => 0, 'http_errors' => 0],
        );

        return response()->json([
            'series' => $points,
            'totals' => $totals,
            'metadata' => [
                'period' => $period,
                'granularity' => $granularity,
                'from' => $from,
                'to' => $to,
                'generated_at' => $to,
            ],
        ]);
    }

    public function webTraffic(
        IndexAdminWebTrafficRequest $request,
        WebAnalyticsService $analytics,
    ): JsonResponse {
        $filters = $request->validated();
        $period = $filters['period'] ?? '30d';
        $granularity = $filters['granularity']
            ?? ($period === '24h' ? 'hour' : 'day');

        return response()->json($analytics->aggregate($period, $granularity));
    }

    public function activeUsers(IndexAdminActiveUsersRequest $request): JsonResponse
    {
        $generatedAt = CarbonImmutable::now();
        $activeSince = $generatedAt->subMinutes(self::ACTIVE_WINDOW_MINUTES);
        $perPage = $this->perPage($request->validated('per_page'));

        $users = User::query()
            ->select([
                'users.id',
                'users.name',
                'users.email',
                'users.role',
                'users.is_active',
            ])
            ->selectSub(function ($query) use ($activeSince): void {
                $query->from('access_logs')
                    ->select('created_at')
                    ->whereColumn('access_logs.user_id', 'users.id')
                    ->where('created_at', '>=', $activeSince)
                    ->latest('created_at')
                    ->latest('id')
                    ->limit(1);
            }, 'last_seen_at')
            ->selectSub(function ($query) use ($activeSince): void {
                $query->from('access_logs')
                    ->select('ip_address')
                    ->whereColumn('access_logs.user_id', 'users.id')
                    ->whereNotNull('ip_address')
                    ->where('created_at', '>=', $activeSince)
                    ->latest('created_at')
                    ->latest('id')
                    ->limit(1);
            }, 'last_ip')
            ->withCount([
                'accessLogs as request_count_last_15m' => fn (Builder $query) => $query
                    ->where('access_logs.created_at', '>=', $activeSince),
                'accessLogs as request_count_last_24h' => fn (Builder $query) => $query
                    ->where('access_logs.created_at', '>=', $generatedAt->subDay()),
            ])
            ->whereHas(
                'accessLogs',
                fn (Builder $query) => $query
                    ->where('access_logs.created_at', '>=', $activeSince),
            )
            ->orderByDesc('last_seen_at')
            ->orderBy('users.id')
            ->paginate($perPage);

        return response()->json([
            'users' => collect($users->items())
                ->map(fn (User $user): array => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'role' => $user->role,
                    'is_active' => $user->is_active,
                    'last_seen_at' => CarbonImmutable::parse($user->last_seen_at),
                    'last_ip' => $user->last_ip,
                    'request_count_last_15m' => (int) $user->request_count_last_15m,
                    'request_count_last_24h' => (int) $user->request_count_last_24h,
                ])
                ->values()
                ->all(),
            'pagination' => $this->pagination($users),
            'metadata' => [
                'generated_at' => $generatedAt,
                'window_minutes' => self::ACTIVE_WINDOW_MINUTES,
                'definition' => $this->activeUsersDefinition(),
            ],
        ]);
    }

    public function heavyUsers(IndexAdminHeavyUsersRequest $request): JsonResponse
    {
        $filters = $request->validated();
        $period = $filters['period'] ?? '7d';
        $to = CarbonImmutable::now();
        $from = match ($period) {
            '24h' => $to->subHours(24),
            '30d' => $to->subDays(30),
            default => $to->subDays(7),
        };
        $perPage = $this->perPage($filters['per_page'] ?? null);

        $accessUsage = DB::table('access_logs')
            ->select('user_id')
            ->selectRaw('COUNT(*) as requests_count')
            ->selectRaw(
                'SUM(CASE WHEN status_code >= 400 THEN 1 ELSE 0 END) as error_requests_count',
            )
            ->selectRaw('MAX(created_at) as last_seen_at')
            ->whereNotNull('user_id')
            ->whereBetween('created_at', [$from, $to])
            ->groupBy('user_id');
        $auditUsage = DB::table('audits')
            ->join('domains', 'domains.id', '=', 'audits.domain_id')
            ->select('domains.user_id')
            ->selectRaw('COUNT(*) as audits_count')
            ->selectRaw(
                'SUM(CASE WHEN audits.status = ? THEN 1 ELSE 0 END) as completed_audits_count',
                [Audit::STATUS_COMPLETED],
            )
            ->selectRaw(
                'SUM(CASE WHEN audits.status = ? THEN 1 ELSE 0 END) as failed_audits_count',
                [Audit::STATUS_FAILED],
            )
            ->whereBetween('audits.created_at', [$from, $to])
            ->groupBy('domains.user_id');
        $recommendationUsage = DB::table('ai_recommendations')
            ->join('audits', 'audits.id', '=', 'ai_recommendations.audit_id')
            ->join('domains', 'domains.id', '=', 'audits.domain_id')
            ->select('domains.user_id')
            ->selectRaw('COUNT(*) as recommendations_count')
            ->whereBetween('ai_recommendations.created_at', [$from, $to])
            ->groupBy('domains.user_id');

        $requests = 'COALESCE(access_usage.requests_count, 0)';
        $errors = 'COALESCE(access_usage.error_requests_count, 0)';
        $audits = 'COALESCE(audit_usage.audits_count, 0)';
        $recommendations = 'COALESCE(recommendation_usage.recommendations_count, 0)';
        // Deterministic ranking from real period activity only.
        $usageScore = sprintf(
            '(%s + (%s * %d) + (%s * %d) + (%s * %d))',
            $requests,
            $audits,
            self::HEAVY_USER_AUDIT_WEIGHT,
            $recommendations,
            self::HEAVY_USER_RECOMMENDATION_WEIGHT,
            $errors,
            self::HEAVY_USER_ERROR_WEIGHT,
        );

        $users = User::query()
            ->select([
                'users.id',
                'users.name',
                'users.email',
                'users.role',
                'users.is_active',
            ])
            ->leftJoinSub($accessUsage, 'access_usage', 'access_usage.user_id', '=', 'users.id')
            ->leftJoinSub($auditUsage, 'audit_usage', 'audit_usage.user_id', '=', 'users.id')
            ->leftJoinSub(
                $recommendationUsage,
                'recommendation_usage',
                'recommendation_usage.user_id',
                '=',
                'users.id',
            )
            ->selectRaw("{$requests} as requests_count")
            ->selectRaw("{$errors} as error_requests_count")
            ->selectRaw("{$audits} as audits_count")
            ->selectRaw('COALESCE(audit_usage.completed_audits_count, 0) as completed_audits_count')
            ->selectRaw('COALESCE(audit_usage.failed_audits_count, 0) as failed_audits_count')
            ->selectRaw("{$recommendations} as recommendations_count")
            ->selectRaw('access_usage.last_seen_at as last_seen_at')
            ->selectRaw("{$usageScore} as usage_score")
            ->where(function (Builder $query): void {
                $query
                    ->whereNotNull('access_usage.user_id')
                    ->orWhereNotNull('audit_usage.user_id')
                    ->orWhereNotNull('recommendation_usage.user_id');
            })
            ->orderByRaw("{$usageScore} DESC")
            ->orderByRaw("{$requests} DESC")
            ->orderByRaw("{$audits} DESC")
            ->orderByDesc('access_usage.last_seen_at')
            ->orderBy('users.id')
            ->paginate($perPage);

        return response()->json([
            'users' => collect($users->items())
                ->map(fn (User $user): array => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'role' => $user->role,
                    'is_active' => $user->is_active,
                    'requests_count' => (int) $user->requests_count,
                    'error_requests_count' => (int) $user->error_requests_count,
                    'audits_count' => (int) $user->audits_count,
                    'completed_audits_count' => (int) $user->completed_audits_count,
                    'failed_audits_count' => (int) $user->failed_audits_count,
                    'recommendations_count' => (int) $user->recommendations_count,
                    'last_seen_at' => $user->last_seen_at === null
                        ? null
                        : CarbonImmutable::parse($user->last_seen_at),
                    'usage_score' => (int) $user->usage_score,
                ])
                ->values()
                ->all(),
            'pagination' => $this->pagination($users),
            'metadata' => [
                'period' => $period,
                'from' => $from,
                'to' => $to,
                'generated_at' => $to,
                'ranking' => 'Usage score descending, then API requests, audits, last seen, and user ID.',
                'usage_score_formula' => 'requests + audits * 10 + recommendations * 8 + errors * 2',
                'api_activity_available' => true,
                'data_sources' => ['users', 'access_logs', 'audits', 'ai_recommendations'],
            ],
        ]);
    }

    private function perPage(mixed $value): int
    {
        return min((int) ($value ?? self::DEFAULT_PER_PAGE), self::MAX_PER_PAGE);
    }

    private function activeUsersDefinition(): string
    {
        return 'Users with API activity recorded in access logs within the last 15 minutes; '.
            'this is activity-based and not true real-time online presence.';
    }

    private function trafficBucketExpression(string $granularity): string
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
     * @return array<string, array{period: string, requests: int, audits: int, recommendations: int, http_errors: int}>
     */
    private function emptyTrafficSeries(
        CarbonImmutable $from,
        CarbonImmutable $to,
        string $granularity,
    ): array {
        $series = [];
        $cursor = $from;

        while ($cursor <= $to) {
            $key = $this->trafficPeriodKey($cursor, $granularity);
            $series[$key] = [
                'period' => $key,
                'requests' => 0,
                'audits' => 0,
                'recommendations' => 0,
                'http_errors' => 0,
            ];
            $cursor = $granularity === 'hour'
                ? $cursor->addHour()
                : $cursor->addDay();
        }

        return $series;
    }

    /**
     * @param  array<string, array{period: string, requests: int, audits: int, recommendations: int, http_errors: int}>  $series
     * @param  Collection<int, mixed>  $rows
     * @param  list<string>  $metrics
     */
    private function mergeTrafficRows(
        array &$series,
        Collection $rows,
        string $granularity,
        array $metrics,
    ): void {
        foreach ($rows as $row) {
            $key = $this->trafficPeriodKey(
                CarbonImmutable::parse((string) $row->period),
                $granularity,
            );

            if (! isset($series[$key])) {
                continue;
            }

            foreach ($metrics as $metric) {
                $series[$key][$metric] = (int) $row->{$metric};
            }
        }
    }

    private function trafficPeriodKey(
        CarbonImmutable $period,
        string $granularity,
    ): string {
        return $granularity === 'hour'
            ? $period->startOfHour()->toIso8601String()
            : $period->format('Y-m-d');
    }

    /**
     * @return array<string, int|string|null>
     */
    private function pagination(LengthAwarePaginator $users): array
    {
        return [
            'current_page' => $users->currentPage(),
            'last_page' => $users->lastPage(),
            'per_page' => $users->perPage(),
            'total' => $users->total(),
            'from' => $users->firstItem(),
            'to' => $users->lastItem(),
            'first_page_url' => $users->url(1),
            'last_page_url' => $users->url($users->lastPage()),
            'previous_page_url' => $users->previousPageUrl(),
            'next_page_url' => $users->nextPageUrl(),
        ];
    }
}
