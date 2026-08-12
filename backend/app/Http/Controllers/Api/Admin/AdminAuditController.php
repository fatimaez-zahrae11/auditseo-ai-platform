<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\IndexAdminAuditRequest;
use App\Models\Audit;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Pagination\LengthAwarePaginator;

class AdminAuditController extends Controller
{
    private const DEFAULT_PER_PAGE = 20;

    private const MAX_PER_PAGE = 100;

    private const SUMMARY_FIELDS = [
        'id',
        'domain_id',
        'status',
        'requested_url',
        'final_url',
        'global_score',
        'technical_score',
        'content_score',
        'links_score',
        'performance_score',
        'created_at',
        'updated_at',
        'completed_at',
        'failed_at',
    ];

    public function index(IndexAdminAuditRequest $request): JsonResponse
    {
        $filters = $request->validated();
        $perPage = min(
            (int) ($filters['per_page'] ?? self::DEFAULT_PER_PAGE),
            self::MAX_PER_PAGE,
        );

        // This global view is safe only because its separate admin route is protected by
        // auth:sanctum, active, and admin middleware; user-facing controllers never use it.
        $audits = Audit::query()
            ->select(self::SUMMARY_FIELDS)
            ->with([
                'domain:id,user_id,domain_name,url',
                'domain.user:id,email',
            ])
            ->when(
                isset($filters['status']),
                fn (Builder $query) => $query->where('status', $filters['status']),
            )
            ->when(
                isset($filters['user_id']),
                fn (Builder $query) => $query->whereHas(
                    'domain',
                    fn (Builder $domainQuery) => $domainQuery
                        ->where('user_id', $filters['user_id']),
                ),
            )
            ->when(
                isset($filters['search']),
                fn (Builder $query) => $this->applySearch($query, $filters['search']),
            )
            ->when(
                isset($filters['created_from']),
                fn (Builder $query) => $query->where(
                    'created_at',
                    '>=',
                    CarbonImmutable::parse($filters['created_from'])->startOfDay(),
                ),
            )
            ->when(
                isset($filters['created_to']),
                fn (Builder $query) => $query->where(
                    'created_at',
                    '<=',
                    CarbonImmutable::parse($filters['created_to'])->endOfDay(),
                ),
            )
            ->latest('id')
            ->paginate($perPage);

        return response()->json([
            'audits' => collect($audits->items())
                ->map(fn (Audit $audit): array => $this->summary($audit))
                ->values()
                ->all(),
            'pagination' => $this->pagination($audits),
        ]);
    }

    private function applySearch(Builder $query, string $search): Builder
    {
        $pattern = '%'.trim($search).'%';

        return $query->where(function (Builder $searchQuery) use ($pattern): void {
            $searchQuery
                ->whereLike('requested_url', $pattern)
                ->orWhereLike('final_url', $pattern)
                ->orWhereHas('domain', function (Builder $domainQuery) use ($pattern): void {
                    $domainQuery
                        ->whereLike('domain_name', $pattern)
                        ->orWhereLike('url', $pattern);
                });
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function summary(Audit $audit): array
    {
        return [
            'id' => $audit->id,
            'status' => $audit->status,
            'requested_url' => $audit->requested_url,
            'final_url' => $audit->final_url,
            'global_score' => $audit->global_score,
            'technical_score' => $audit->technical_score,
            'content_score' => $audit->content_score,
            'links_score' => $audit->links_score,
            'performance_score' => $audit->performance_score,
            'created_at' => $audit->created_at,
            'updated_at' => $audit->updated_at,
            'completed_at' => $audit->completed_at,
            'failed_at' => $audit->failed_at,
            'domain' => [
                'name' => $audit->domain->domain_name,
                'url' => $audit->domain->url,
            ],
            'user' => [
                'id' => $audit->domain->user->id,
                'email' => $audit->domain->user->email,
            ],
        ];
    }

    /**
     * @return array<string, int|string|null>
     */
    private function pagination(LengthAwarePaginator $audits): array
    {
        return [
            'current_page' => $audits->currentPage(),
            'last_page' => $audits->lastPage(),
            'per_page' => $audits->perPage(),
            'total' => $audits->total(),
            'from' => $audits->firstItem(),
            'to' => $audits->lastItem(),
            'first_page_url' => $audits->url(1),
            'last_page_url' => $audits->url($audits->lastPage()),
            'previous_page_url' => $audits->previousPageUrl(),
            'next_page_url' => $audits->nextPageUrl(),
        ];
    }
}
