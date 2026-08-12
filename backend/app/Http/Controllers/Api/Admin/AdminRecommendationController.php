<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\IndexAdminRecommendationRequest;
use App\Models\AiRecommendation;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Str;

class AdminRecommendationController extends Controller
{
    private const DEFAULT_PER_PAGE = 20;

    private const MAX_PER_PAGE = 100;

    private const PREVIEW_CHARACTERS = 300;

    public function index(IndexAdminRecommendationRequest $request): JsonResponse
    {
        $filters = $request->validated();
        $perPage = min(
            (int) ($filters['per_page'] ?? self::DEFAULT_PER_PAGE),
            self::MAX_PER_PAGE,
        );

        // This global view is safe only because its separate admin route is protected by
        // auth:sanctum, active, and admin middleware; user-facing controllers never use it.
        $recommendations = AiRecommendation::query()
            ->select([
                'ai_recommendations.id',
                'ai_recommendations.audit_id',
                'ai_recommendations.created_at',
                'ai_recommendations.updated_at',
            ])
            ->selectRaw(
                'SUBSTR(ai_recommendations.generated_text, 1, ?) as generated_text_preview',
                [self::PREVIEW_CHARACTERS],
            )
            ->with([
                'audit:id,domain_id,requested_url,final_url',
                'audit.domain:id,user_id',
                'audit.domain.user:id,email',
            ])
            ->when(
                isset($filters['user_id']),
                fn (Builder $query) => $query->whereHas(
                    'audit.domain',
                    fn (Builder $domainQuery) => $domainQuery
                        ->where('user_id', $filters['user_id']),
                ),
            )
            ->when(
                isset($filters['audit_id']),
                fn (Builder $query) => $query->where('audit_id', $filters['audit_id']),
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
            ->latest('created_at')
            ->latest('id')
            ->paginate($perPage);

        return response()->json([
            'recommendations' => collect($recommendations->items())
                ->map(fn (AiRecommendation $recommendation): array => $this->summary($recommendation))
                ->values()
                ->all(),
            'pagination' => $this->pagination($recommendations),
        ]);
    }

    private function applySearch(Builder $query, string $search): Builder
    {
        $pattern = '%'.trim($search).'%';

        return $query->whereHas('audit', function (Builder $auditQuery) use ($pattern): void {
            $auditQuery->where(function (Builder $searchQuery) use ($pattern): void {
                $searchQuery
                    ->whereLike('requested_url', $pattern)
                    ->orWhereLike('final_url', $pattern)
                    ->orWhereHas(
                        'domain.user',
                        fn (Builder $userQuery) => $userQuery->whereLike('email', $pattern),
                    );
            });
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function summary(AiRecommendation $recommendation): array
    {
        return [
            'id' => $recommendation->id,
            'audit_id' => $recommendation->audit_id,
            'user' => [
                'id' => $recommendation->audit->domain->user->id,
                'email' => $recommendation->audit->domain->user->email,
            ],
            'audit' => [
                'requested_url' => $recommendation->audit->requested_url,
                'final_url' => $recommendation->audit->final_url,
            ],
            'generated_text_preview' => Str::substr(
                (string) $recommendation->generated_text_preview,
                0,
                self::PREVIEW_CHARACTERS,
            ),
            'created_at' => $recommendation->created_at,
            'updated_at' => $recommendation->updated_at,
        ];
    }

    /**
     * @return array<string, int|string|null>
     */
    private function pagination(LengthAwarePaginator $recommendations): array
    {
        return [
            'current_page' => $recommendations->currentPage(),
            'last_page' => $recommendations->lastPage(),
            'per_page' => $recommendations->perPage(),
            'total' => $recommendations->total(),
            'from' => $recommendations->firstItem(),
            'to' => $recommendations->lastItem(),
            'first_page_url' => $recommendations->url(1),
            'last_page_url' => $recommendations->url($recommendations->lastPage()),
            'previous_page_url' => $recommendations->previousPageUrl(),
            'next_page_url' => $recommendations->nextPageUrl(),
        ];
    }
}
