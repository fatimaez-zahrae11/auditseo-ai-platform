<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\DeactivateAdminUserRequest;
use App\Http\Requests\Admin\StoreAdminUserRequest;
use App\Models\AccessLog;
use App\Models\AdminActionLog;
use App\Models\Audit;
use App\Models\User;
use App\Services\AdminActionLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class AdminUserController extends Controller
{
    private const DEFAULT_PER_PAGE = 20;

    private const MAX_PER_PAGE = 100;

    public function index(Request $request): JsonResponse
    {
        $perPage = max(
            1,
            min($request->integer('per_page', self::DEFAULT_PER_PAGE), self::MAX_PER_PAGE),
        );

        // This global user query is permitted only behind the admin route middleware group.
        $users = User::query()
            ->select([
                'users.id',
                'users.name',
                'users.email',
                'users.role',
                'users.is_active',
                'users.email_verified_at',
                'users.created_at',
            ])
            ->withCount([
                'audits',
                'audits as completed_audits_count' => fn ($query) => $query
                    ->where('audits.status', Audit::STATUS_COMPLETED),
                'audits as failed_audits_count' => fn ($query) => $query
                    ->where('audits.status', Audit::STATUS_FAILED),
            ])
            ->selectSub(function ($query) {
                $query->from('ai_recommendations')
                    ->join('audits', 'audits.id', '=', 'ai_recommendations.audit_id')
                    ->join('domains', 'domains.id', '=', 'audits.domain_id')
                    ->selectRaw('COUNT(*)')
                    ->whereColumn('domains.user_id', 'users.id');
            }, 'recommendations_count')
            ->latest('users.id')
            ->paginate($perPage);

        return response()->json([
            'users' => collect($users->items())
                ->map(fn (User $user): array => $this->listingSummary($user))
                ->values()
                ->all(),
            'pagination' => $this->pagination($users),
        ]);
    }

    public function store(
        StoreAdminUserRequest $request,
        AdminActionLogger $actionLogger,
    ): JsonResponse {
        $user = User::create([
            'name' => $request->validated('name'),
            'email' => $request->validated('email'),
            'password' => Hash::make($request->validated('password')),
        ]);

        $actionLogger->log(
            $request->user(),
            AdminActionLog::ACTION_USER_CREATED,
            $user,
            request: $request,
        );
        $user->sendEmailVerificationNotification();

        return response()->json([
            'message' => 'User created successfully. Email verification is required.',
            'user' => $this->identity($user),
        ], 201);
    }

    public function deactivate(
        DeactivateAdminUserRequest $request,
        User $user,
        AdminActionLogger $actionLogger,
    ): JsonResponse {
        $adminId = (int) $request->user()->id;
        $blockedReason = $request->validated('blocked_reason');

        $user = DB::transaction(function () use ($adminId, $blockedReason, $user): User {
            $target = User::query()->lockForUpdate()->findOrFail($user->id);
            $activeAdminIds = User::query()
                ->where('role', User::ROLE_ADMIN)
                ->where('is_active', true)
                ->lockForUpdate()
                ->pluck('id');

            if ($target->isAdmin() && $target->is_active && $activeAdminIds->count() <= 1) {
                abort(422, 'The last active admin cannot be deactivated.');
            }

            if ($target->id === $adminId) {
                abort(422, 'You cannot deactivate your own account.');
            }

            $target->forceFill([
                'is_active' => false,
                'blocked_at' => now(),
                'blocked_reason' => $blockedReason,
                'blocked_by' => $adminId,
            ])->save();

            $target->tokens()->delete();

            return $target->refresh();
        });

        $actionLogger->log(
            $request->user(),
            AdminActionLog::ACTION_USER_DEACTIVATED,
            $user,
            request: $request,
        );

        return response()->json([
            'message' => 'User deactivated successfully.',
            'user' => $this->identity($user),
        ]);
    }

    public function reactivate(
        Request $request,
        User $user,
        AdminActionLogger $actionLogger,
    ): JsonResponse {
        $user->forceFill([
            'is_active' => true,
            'blocked_at' => null,
            'blocked_reason' => null,
            'blocked_by' => null,
        ])->save();

        $actionLogger->log(
            $request->user(),
            AdminActionLog::ACTION_USER_REACTIVATED,
            $user,
            request: $request,
        );

        return response()->json([
            'message' => 'User reactivated successfully.',
            'user' => $this->identity($user->refresh()),
        ]);
    }

    public function activity(User $user): JsonResponse
    {
        // This global activity view is available only through the protected admin route group.
        $latestLog = AccessLog::query()
            ->where('user_id', $user->id)
            ->latest('created_at')
            ->latest('id')
            ->first();
        $lastIp = AccessLog::query()
            ->where('user_id', $user->id)
            ->whereNotNull('ip_address')
            ->latest('created_at')
            ->latest('id')
            ->value('ip_address');
        $requestCounts = AccessLog::query()
            ->where('user_id', $user->id)
            ->selectRaw(
                'SUM(CASE WHEN created_at >= ? THEN 1 ELSE 0 END) as last_24h, '.
                'SUM(CASE WHEN created_at >= ? THEN 1 ELSE 0 END) as last_7d',
                [now()->subDay(), now()->subDays(7)],
            )
            ->first();
        $recentRoutes = AccessLog::query()
            ->where('user_id', $user->id)
            ->latest('created_at')
            ->latest('id')
            ->limit(10)
            ->get([
                'route',
                'method',
                'status_code',
                'created_at',
            ])
            ->map(fn (AccessLog $log): array => [
                'route' => $log->route,
                'method' => $log->method,
                'status_code' => $log->status_code,
                'created_at' => $log->created_at,
            ])
            ->all();
        $aggregateUser = User::query()
            ->select(['users.id'])
            ->withCount([
                'audits',
                'audits as completed_audits_count' => fn ($query) => $query
                    ->where('audits.status', Audit::STATUS_COMPLETED),
                'audits as failed_audits_count' => fn ($query) => $query
                    ->where('audits.status', Audit::STATUS_FAILED),
            ])
            ->selectSub(function ($query) {
                $query->from('ai_recommendations')
                    ->join('audits', 'audits.id', '=', 'ai_recommendations.audit_id')
                    ->join('domains', 'domains.id', '=', 'audits.domain_id')
                    ->selectRaw('COUNT(*)')
                    ->whereColumn('domains.user_id', 'users.id');
            }, 'recommendations_count')
            ->findOrFail($user->id);

        return response()->json([
            'user' => [
                'id' => $user->id,
                'email' => $user->email,
            ],
            'last_seen_at' => $latestLog?->created_at,
            'last_ip' => $lastIp,
            'request_count_last_24h' => (int) ($requestCounts?->last_24h ?? 0),
            'request_count_last_7d' => (int) ($requestCounts?->last_7d ?? 0),
            'recent_routes' => $recentRoutes,
            'audits_count' => (int) $aggregateUser->audits_count,
            'completed_audits_count' => (int) $aggregateUser->completed_audits_count,
            'failed_audits_count' => (int) $aggregateUser->failed_audits_count,
            'recommendations_count' => (int) $aggregateUser->recommendations_count,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function listingSummary(User $user): array
    {
        return [
            ...$this->identity($user),
            'audits_count' => (int) ($user->audits_count ?? 0),
            'completed_audits_count' => (int) ($user->completed_audits_count ?? 0),
            'failed_audits_count' => (int) ($user->failed_audits_count ?? 0),
            'recommendations_count' => (int) ($user->recommendations_count ?? 0),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function identity(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->role,
            'is_active' => $user->is_active,
            'email_verified_at' => $user->email_verified_at,
            'created_at' => $user->created_at,
        ];
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
