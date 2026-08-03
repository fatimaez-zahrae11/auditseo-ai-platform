<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreAuditRequest;
use App\Jobs\RunSeoAuditJob;
use App\Models\Audit;
use App\Models\Domain;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AuditController extends Controller
{
    public function store(StoreAuditRequest $request): JsonResponse
    {
        $url = $request->validated('url');
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

        RunSeoAuditJob::dispatch($audit->id);

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
