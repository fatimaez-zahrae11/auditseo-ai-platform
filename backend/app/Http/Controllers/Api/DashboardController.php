<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AiRecommendation;
use App\Models\Audit;
use App\Models\AuditIssue;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $userId = $request->user()->id;
        $ownedAudits = Audit::query()
            ->whereHas('domain', fn ($query) => $query->where('user_id', $userId));

        $totalAudits = (clone $ownedAudits)->count();
        $averageGlobalScore = $totalAudits === 0
            ? 0
            : (int) round((float) (clone $ownedAudits)->avg('global_score'));

        $totalIssues = AuditIssue::query()
            ->whereHas('audit.domain', fn ($query) => $query->where('user_id', $userId))
            ->count();

        $totalAiRecommendations = AiRecommendation::query()
            ->whereHas('audit.domain', fn ($query) => $query->where('user_id', $userId))
            ->count();

        $latestAudit = (clone $ownedAudits)
            ->with('domain')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->first();

        return response()->json([
            'total_audits' => $totalAudits,
            'average_global_score' => $averageGlobalScore,
            'total_issues' => $totalIssues,
            'total_ai_recommendations' => $totalAiRecommendations,
            'latest_audit' => $latestAudit,
        ]);
    }
}
