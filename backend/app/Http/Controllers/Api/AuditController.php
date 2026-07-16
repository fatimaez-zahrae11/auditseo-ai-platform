<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreAuditRequest;
use App\Models\Audit;
use App\Models\Domain;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AuditController extends Controller
{
    #store() crée un audit. Il prend l’URL validée, extrait le domaine avec parse_url
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
        ]);

        return response()->json([
            'message' => 'Audit created successfully.',
            'audit' => $audit->load('domain'),
        ], 201);
    }

    #retourne seulement les audits de l’utilisateur connecté.
    public function index(Request $request): JsonResponse
    {
        $audits = Audit::query()
            ->with('domain')
            ->whereHas('domain', fn ($query) => $query->where('user_id', $request->user()->id))
            ->latest()
            ->get();

        return response()->json([
            'audits' => $audits,
        ]);
    }

    #show() retourne un seul audit, mais seulement si cet audit appartient à l’utilisateur connecté. Si l’utilisateur essaie d’accéder à l’audit d’un autre utilisateur, Laravel retourne 404. C’est la protection IDOR.
    public function show(Request $request, int $id): JsonResponse
    {
        $audit = Audit::query()
            ->with('domain')
            ->whereHas('domain', fn ($query) => $query->where('user_id', $request->user()->id))
            ->findOrFail($id);

        return response()->json([
            'audit' => $audit,
        ]);
    }
}
