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

        $statusCounts = (clone $ownedAudits)
            ->selectRaw('status, COUNT(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');
        $totalAudits = (int) $statusCounts->sum();
        $completedAudits = (int) $statusCounts->get(Audit::STATUS_COMPLETED, 0);
        $pendingAudits = (int) $statusCounts->get(Audit::STATUS_PENDING, 0);
        $runningAudits = (int) $statusCounts->get(Audit::STATUS_RUNNING, 0);
        $failedAudits = (int) $statusCounts->get(Audit::STATUS_FAILED, 0);

        $averageGlobalScore = $completedAudits === 0
            ? 0
            : (int) round((float) (clone $ownedAudits)
                ->where('status', Audit::STATUS_COMPLETED)
                ->avg('global_score'));

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

        $latestCompletedAudit = (clone $ownedAudits)
            ->where('status', Audit::STATUS_COMPLETED)
            ->with('domain')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->first();

        return response()->json([
            'total_audits' => $totalAudits,
            'completed_audits' => $completedAudits,
            'pending_audits' => $pendingAudits,
            'running_audits' => $runningAudits,
            'failed_audits' => $failedAudits,
            'average_global_score' => $averageGlobalScore,
            'total_issues' => $totalIssues,
            'total_ai_recommendations' => $totalAiRecommendations,
            'latest_audit' => $latestAudit,
            'latest_completed_audit' => $latestCompletedAudit,
        ]);
    }
}
