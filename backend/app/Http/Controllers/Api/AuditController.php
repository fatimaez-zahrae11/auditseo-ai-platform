<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\CrawlUnavailableException;
use App\Http\Controllers\Controller;
use App\Http\Requests\IndexAuditRequest;
use App\Http\Requests\StoreAuditRequest;
use App\Jobs\RunSeoAuditJob;
use App\Models\ActionLog;
use App\Models\Audit;
use App\Models\Domain;
use App\Services\ActionLogger;
use App\Support\AuditUrl;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Throwable;

class AuditController extends Controller
{
    private const SUMMARY_FIELDS = [
        'id',
        'domain_id',
        'requested_url',
        'final_url',
        'status',
        'global_score',
        'technical_score',
        'content_score',
        'links_score',
        'performance_score',
        'created_at',
        'updated_at',
        'started_at',
        'completed_at',
        'failed_at',
    ];

    public function store(StoreAuditRequest $request, ActionLogger $actionLogger): JsonResponse
    {
        $url = AuditUrl::canonicalizeForStorage($request->validated('url'));
        $domainName = strtolower((string) parse_url($url, PHP_URL_HOST));

        $domain = Domain::firstOrCreate(
            [
                'user_id' => $request->user()->id,
                'domain_name' => $domainName,
            ],
            ['url' => $url],
        );

        $audit = $domain->audits()->create([
            'global_score' => 0,
            'technical_score' => 0,
            'content_score' => 0,
            'links_score' => 0,
            'performance_score' => 0,
            'raw_data' => null,
            'requested_url' => $url,
            'status' => Audit::STATUS_PENDING,
        ]);

        try {
            RunSeoAuditJob::dispatch($audit->id);
        } catch (Throwable $exception) {
            $audit->update([
                'status' => Audit::STATUS_FAILED,
                'completed_at' => null,
                'failed_at' => now(),
                'failure_reason' => 'Audit dispatch failed.',
            ]);

            Log::warning('SEO audit dispatch failed.', [
                'audit_id' => $audit->id,
                'exception' => $exception::class,
            ]);
            $actionLogger->log(
                $request->user(),
                ActionLog::ACTION_AUDIT_CREATED,
                $audit,
                ActionLog::STATUS_FAILURE,
                ['new_status' => Audit::STATUS_FAILED],
            );

            return response()->json([
                'message' => 'Audit service is temporarily unavailable.',
            ], 503);
        }

        $actionLogger->log(
            $request->user(),
            ActionLog::ACTION_AUDIT_CREATED,
            $audit,
            metadata: ['new_status' => Audit::STATUS_PENDING],
        );

        return response()->json([
            'message' => 'Audit queued for processing.',
            'audit' => [
                'id' => $audit->id,
                'status' => $audit->status,
                'requested_url' => $audit->requested_url,
            ],
            'poll_url' => "/api/audits/{$audit->id}",
        ], 202);
    }

    // index() & show() ces 2 fcts gardent la protection IDOR:
    // retourner seulement les audits de l’utilisateur connecte
    public function index(IndexAuditRequest $request): JsonResponse
    {
        $filters = $request->validated();
        $audits = Audit::query()
            ->select(self::SUMMARY_FIELDS)
            ->whereHas('domain', fn ($query) => $query->where('user_id', $request->user()->id))
            ->when(
                isset($filters['status']),
                fn (Builder $query) => $query->where('status', $filters['status']),
            )
            ->when(
                isset($filters['search']),
                fn (Builder $query) => $this->applySearch($query, $filters['search']),
            )
            ->latest('id')
            ->paginate(20)
            ->withQueryString();

        return response()->json([
            'audits' => collect($audits->items())
                ->map(fn (Audit $audit): array => Arr::only($audit->toArray(), self::SUMMARY_FIELDS))
                ->values()
                ->all(),
            'pagination' => [
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
            ],
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

    // meme logique ; donc si user A essaie de voir laudit de l'utilisateur B ? laravel return 404 error C la protection IDOR
    public function show(Request $request, int $id): JsonResponse
    {
        $audit = Audit::query()
            ->with(['domain', 'issues'])
            ->whereHas('domain', fn ($query) => $query->where('user_id', $request->user()->id))
            ->findOrFail($id);

        $payload = $audit->toArray();
        if ($audit->status === Audit::STATUS_FAILED
            && $audit->failure_reason === CrawlUnavailableException::PUBLIC_MESSAGE) {
            $payload['failure_message'] = CrawlUnavailableException::PUBLIC_MESSAGE;
        }

        return response()->json([
            'audit' => $payload,
        ]);
    }
}
