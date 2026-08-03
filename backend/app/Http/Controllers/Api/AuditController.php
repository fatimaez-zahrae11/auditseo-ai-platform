<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreAuditRequest;
use App\Models\Audit;
use App\Models\Domain;
use App\Services\Audit\AuditProcessingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

class AuditController extends Controller
{
    private const AUDIT_EXECUTION_TIME_LIMIT_SECONDS = 180;

    public function store(
        StoreAuditRequest $request,
        AuditProcessingService $processingService,
    ): JsonResponse {
        $url = $request->validated('url');
        $domainName = strtolower((string) parse_url($url, PHP_URL_HOST));

        $domain = Domain::firstOrCreate(
            [
                'user_id' => $request->user()->id,
                'domain_name' => $domainName,
            ],
            ['url' => $url],
        );

        $this->extendExecutionTimeForAudit();
        $startedAt = now();

        try {
            $rawData = $processingService->crawl($url);
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            Log::warning('Synchronous SEO audit crawl failed.', [
                'exception' => $exception::class,
            ]);

            return response()->json([
                'message' => 'Unable to fetch the requested URL.',
            ], 502);
        }

        $audit = $processingService->createCompletedAudit(
            $domain,
            $url,
            $rawData,
            $startedAt,
        );
        $audit->load(['domain', 'issues']);

        return response()->json([
            'message' => 'Audit created successfully.',
            'audit' => $audit,
            'domain' => $audit->domain,
            'issues' => $audit->issues,
            'raw_data' => $audit->raw_data,
        ], 201);
    }

    private function extendExecutionTimeForAudit(): void
    {
        if (function_exists('set_time_limit')) {
            @set_time_limit(self::AUDIT_EXECUTION_TIME_LIMIT_SECONDS);
        }

        if (function_exists('ini_set')) {
            @ini_set('max_execution_time', (string) self::AUDIT_EXECUTION_TIME_LIMIT_SECONDS);
        }
    }

    // index() & show() ces 2 fcts gardent la protection IDOR:
    // retourner seulement les audits de l’utilisateur connecte
    public function index(Request $request): JsonResponse
    {
        $audits = Audit::query()
            ->with('domain')
            ->whereHas('domain', fn ($query) => $query->where('user_id', $request->user()->id))
            ->latest()
            ->paginate(20);

        return response()->json([
            'audits' => $audits->items(),
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

    // meme logique ; donc si user A essaie de voir laudit de l'utilisateur B ? laravel return 404 error C la protection IDOR
    public function show(Request $request, int $id): JsonResponse
    {
        $audit = Audit::query()
            ->with(['domain', 'issues'])
            ->whereHas('domain', fn ($query) => $query->where('user_id', $request->user()->id))
            ->findOrFail($id);

        return response()->json([
            'audit' => $audit,
        ]);
    }
}
