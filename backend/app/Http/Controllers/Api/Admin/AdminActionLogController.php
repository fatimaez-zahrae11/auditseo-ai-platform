<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\IndexAdminActionLogRequest;
use App\Models\ActionLog;
use App\Services\ActionLogger;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Pagination\LengthAwarePaginator;

class AdminActionLogController extends Controller
{
    private const DEFAULT_PER_PAGE = 20;

    private const MAX_PER_PAGE = 100;

    public function index(
        IndexAdminActionLogRequest $request,
        ActionLogger $actionLogger,
    ): JsonResponse {
        $filters = $request->validated();
        $perPage = min(
            (int) ($filters['per_page'] ?? self::DEFAULT_PER_PAGE),
            self::MAX_PER_PAGE,
        );

        // This semantic product audit trail is visible only behind auth:sanctum, active, and admin.
        $logs = ActionLog::query()
            ->select([
                'id',
                'actor_user_id',
                'actor_role',
                'actor_name',
                'actor_email',
                'action',
                'entity_type',
                'entity_id',
                'status',
                'metadata',
                'created_at',
            ])
            ->when(
                isset($filters['role']) && $filters['role'] !== 'all',
                fn (Builder $query) => $query->where('actor_role', $filters['role']),
            )
            ->when(
                isset($filters['actor_user_id']),
                fn (Builder $query) => $query->where('actor_user_id', $filters['actor_user_id']),
            )
            ->when(
                isset($filters['q']),
                fn (Builder $query) => $this->applySearch($query, $filters['q']),
            )
            ->when(
                isset($filters['action']),
                fn (Builder $query) => $query->where('action', $filters['action']),
            )
            ->when(
                isset($filters['entity_type']),
                fn (Builder $query) => $query->where('entity_type', $filters['entity_type']),
            )
            ->when(
                isset($filters['status']),
                fn (Builder $query) => $query->where('status', $filters['status']),
            )
            ->when(
                isset($filters['date_from']),
                fn (Builder $query) => $query->where(
                    'created_at',
                    '>=',
                    CarbonImmutable::parse($filters['date_from'])->startOfDay(),
                ),
            )
            ->when(
                isset($filters['date_to']),
                fn (Builder $query) => $query->where(
                    'created_at',
                    '<=',
                    CarbonImmutable::parse($filters['date_to'])->endOfDay(),
                ),
            )
            ->latest('created_at')
            ->latest('id')
            ->paginate($perPage);

        return response()->json([
            'data' => collect($logs->items())
                ->map(fn (ActionLog $log): array => [
                    'id' => $log->id,
                    'actor' => [
                        'id' => $log->actor_user_id,
                        'name' => $log->actor_name ?? 'System',
                        'email' => $log->actor_email,
                        'role' => $log->actor_role ?? ActionLog::ROLE_SYSTEM,
                    ],
                    'action' => $log->action,
                    'entity_type' => $log->entity_type,
                    'entity_id' => $log->entity_id,
                    'status' => $log->status,
                    'metadata_summary' => $actionLogger->metadataSummary($log->metadata),
                    'created_at' => $log->created_at,
                ])
                ->values()
                ->all(),
            'meta' => $this->pagination($logs),
        ]);
    }

    private function applySearch(Builder $query, string $search): Builder
    {
        $pattern = '%'.trim($search).'%';

        return $query->where(function (Builder $searchQuery) use ($pattern): void {
            $searchQuery
                ->whereLike('actor_name', $pattern)
                ->orWhereLike('actor_email', $pattern)
                ->orWhereLike('action', $pattern)
                ->orWhereLike('entity_type', $pattern);
        });
    }

    /**
     * @return array<string, int|string|null>
     */
    private function pagination(LengthAwarePaginator $logs): array
    {
        return [
            'current_page' => $logs->currentPage(),
            'last_page' => $logs->lastPage(),
            'per_page' => $logs->perPage(),
            'total' => $logs->total(),
            'from' => $logs->firstItem(),
            'to' => $logs->lastItem(),
            'previous_page_url' => $logs->previousPageUrl(),
            'next_page_url' => $logs->nextPageUrl(),
        ];
    }
}
