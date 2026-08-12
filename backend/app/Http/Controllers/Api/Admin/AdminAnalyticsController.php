<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\IndexAdminActiveUsersRequest;
use App\Http\Requests\Admin\IndexAdminHeavyUsersRequest;
use App\Models\AccessLog;
use App\Models\AiRecommendation;
use App\Models\Audit;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Pagination\LengthAwarePaginator;

class AdminAnalyticsController extends Controller
{
    private const ACTIVE_WINDOW_MINUTES = 15;

    private const DEFAULT_PER_PAGE = 20;

    private const MAX_PER_PAGE = 100;

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
        [$from, $to] = $this->period($filters);
        $perPage = $this->perPage($filters['per_page'] ?? null);

        $users = User::query()
            ->select([
                'users.id',
                'users.name',
                'users.email',
            ])
            ->withCount([
                'accessLogs as requests_count' => fn (Builder $query) => $query
                    ->whereBetween('access_logs.created_at', [$from, $to]),
                'audits as audits_count' => fn (Builder $query) => $query
                    ->whereBetween('audits.created_at', [$from, $to]),
                'audits as completed_audits_count' => fn (Builder $query) => $query
                    ->where('audits.status', Audit::STATUS_COMPLETED)
                    ->whereBetween('audits.created_at', [$from, $to]),
                'audits as failed_audits_count' => fn (Builder $query) => $query
                    ->where('audits.status', Audit::STATUS_FAILED)
                    ->whereBetween('audits.created_at', [$from, $to]),
            ])
            ->selectSub(function ($query) use ($from, $to): void {
                $query->from('ai_recommendations')
                    ->join('audits', 'audits.id', '=', 'ai_recommendations.audit_id')
                    ->join('domains', 'domains.id', '=', 'audits.domain_id')
                    ->selectRaw('COUNT(*)')
                    ->whereColumn('domains.user_id', 'users.id')
                    ->whereBetween('ai_recommendations.created_at', [$from, $to]);
            }, 'recommendations_count')
            ->whereHas(
                'accessLogs',
                fn (Builder $query) => $query
                    ->whereBetween('access_logs.created_at', [$from, $to]),
            )
            ->orderByDesc('requests_count')
            ->orderByDesc('audits_count')
            ->orderBy('users.id')
            ->paginate($perPage);

        return response()->json([
            'users' => collect($users->items())
                ->map(fn (User $user): array => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'requests_count' => (int) $user->requests_count,
                    'audits_count' => (int) $user->audits_count,
                    'completed_audits_count' => (int) $user->completed_audits_count,
                    'failed_audits_count' => (int) $user->failed_audits_count,
                    'recommendations_count' => (int) $user->recommendations_count,
                ])
                ->values()
                ->all(),
            'pagination' => $this->pagination($users),
            'metadata' => [
                'from' => $from,
                'to' => $to,
                'ranking' => 'Attributed API request count descending, then audit count descending.',
            ],
        ]);
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array{CarbonImmutable, CarbonImmutable}
     */
    private function period(array $filters): array
    {
        $to = isset($filters['to'])
            ? $this->parseBoundary($filters['to'], false)
            : CarbonImmutable::now();
        $from = isset($filters['from'])
            ? $this->parseBoundary($filters['from'], true)
            : $to->subDays(7);

        return [$from, $to];
    }

    private function parseBoundary(string $value, bool $start): CarbonImmutable
    {
        $date = CarbonImmutable::parse($value);

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            return $date;
        }

        return $start ? $date->startOfDay() : $date->endOfDay();
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
