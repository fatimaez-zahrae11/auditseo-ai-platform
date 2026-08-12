<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\IndexAdminActionLogRequest;
use App\Models\AdminActionLog;
use App\Services\AdminActionLogger;
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
        AdminActionLogger $actionLogger,
    ): JsonResponse {
        $filters = $request->validated();
        $perPage = min(
            (int) ($filters['per_page'] ?? self::DEFAULT_PER_PAGE),
            self::MAX_PER_PAGE,
        );

        // This global audit trail is safe only behind auth:sanctum, active, and admin.
        $logs = AdminActionLog::query()
            ->select([
                'id',
                'admin_user_id',
                'action',
                'target_type',
                'target_id',
                'metadata',
                'ip_address',
                'created_at',
            ])
            ->with('adminUser:id,email')
            ->when(
                isset($filters['admin_user_id']),
                fn (Builder $query) => $query
                    ->where('admin_user_id', $filters['admin_user_id']),
            )
            ->when(
                isset($filters['action']),
                fn (Builder $query) => $query->where('action', $filters['action']),
            )
            ->when(
                isset($filters['target_type']),
                fn (Builder $query) => $query->where('target_type', $filters['target_type']),
            )
            ->when(
                isset($filters['target_id']),
                fn (Builder $query) => $query->where('target_id', $filters['target_id']),
            )
            ->when(
                isset($filters['created_from']),
                fn (Builder $query) => $query->where(
                    'created_at',
                    '>=',
                    $this->dateBoundary($filters['created_from'], true),
                ),
            )
            ->when(
                isset($filters['created_to']),
                fn (Builder $query) => $query->where(
                    'created_at',
                    '<=',
                    $this->dateBoundary($filters['created_to'], false),
                ),
            )
            ->latest('created_at')
            ->latest('id')
            ->paginate($perPage);

        return response()->json([
            'action_logs' => collect($logs->items())
                ->map(fn (AdminActionLog $log): array => [
                    'id' => $log->id,
                    'admin_user_id' => $log->admin_user_id,
                    'admin_user_email' => $log->adminUser?->email,
                    'action' => $log->action,
                    'target_type' => $log->target_type,
                    'target_id' => $log->target_id,
                    'metadata' => $log->metadata === null
                        ? null
                        : $actionLogger->sanitizeMetadata($log->metadata),
                    'ip_address' => $log->ip_address,
                    'created_at' => $log->created_at,
                ])
                ->values()
                ->all(),
            'pagination' => $this->pagination($logs),
        ]);
    }

    private function dateBoundary(string $value, bool $start): CarbonImmutable
    {
        $date = CarbonImmutable::parse($value);

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            return $date;
        }

        return $start ? $date->startOfDay() : $date->endOfDay();
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
            'first_page_url' => $logs->url(1),
            'last_page_url' => $logs->url($logs->lastPage()),
            'previous_page_url' => $logs->previousPageUrl(),
            'next_page_url' => $logs->nextPageUrl(),
        ];
    }
}
