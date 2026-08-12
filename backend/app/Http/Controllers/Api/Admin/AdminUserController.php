<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\DeactivateAdminUserRequest;
use App\Http\Requests\Admin\StoreAdminUserRequest;
use App\Models\Audit;
use App\Models\User;
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

    public function store(StoreAdminUserRequest $request): JsonResponse
    {
        $user = User::create([
            'name' => $request->validated('name'),
            'email' => $request->validated('email'),
            'password' => Hash::make($request->validated('password')),
        ]);

        $user->sendEmailVerificationNotification();

        return response()->json([
            'message' => 'User created successfully. Email verification is required.',
            'user' => $this->identity($user),
        ], 201);
    }

    public function deactivate(DeactivateAdminUserRequest $request, User $user): JsonResponse
    {
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

        return response()->json([
            'message' => 'User deactivated successfully.',
            'user' => $this->identity($user),
        ]);
    }

    public function reactivate(Request $request, User $user): JsonResponse
    {
        $user->forceFill([
            'is_active' => true,
            'blocked_at' => null,
            'blocked_reason' => null,
            'blocked_by' => null,
        ])->save();

        return response()->json([
            'message' => 'User reactivated successfully.',
            'user' => $this->identity($user->refresh()),
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
