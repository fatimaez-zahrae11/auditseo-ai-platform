<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\AiRecommendationException;
use App\Http\Controllers\Controller;
use App\Models\ActionLog;
use App\Models\Audit;
use App\Services\ActionLogger;
use App\Services\Ai\AiRecommendationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class AiRecommendationController extends Controller
{
    private const GENERATION_LOCK_SECONDS = 180;

    private const DEFAULT_HISTORY_PAGE_SIZE = 20;

    private const MAX_HISTORY_PAGE_SIZE = 50;

    public function index(Request $request, int $audit): JsonResponse
    {
        $ownedAudit = Audit::query()
            ->whereHas('domain', fn ($query) => $query->where('user_id', $request->user()->id))
            ->findOrFail($audit);

        $perPage = max(
            1,
            min($request->integer('per_page', self::DEFAULT_HISTORY_PAGE_SIZE), self::MAX_HISTORY_PAGE_SIZE),
        );

        $recommendations = $ownedAudit->aiRecommendations()
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate($perPage);

        return response()->json([
            'recommendations' => $recommendations->items(),
            'pagination' => [
                'current_page' => $recommendations->currentPage(),
                'last_page' => $recommendations->lastPage(),
                'per_page' => $recommendations->perPage(),
                'total' => $recommendations->total(),
                'from' => $recommendations->firstItem(),
                'to' => $recommendations->lastItem(),
                'next_page_url' => $recommendations->nextPageUrl(),
                'previous_page_url' => $recommendations->previousPageUrl(),
            ],
        ]);
    }

    public function store(
        Request $request,
        int $audit,
        AiRecommendationService $service,
        ActionLogger $actionLogger,
    ): JsonResponse {
        $ownedAudit = Audit::query()
            ->with(['domain', 'issues'])
            ->whereHas('domain', fn ($query) => $query->where('user_id', $request->user()->id))
            ->findOrFail($audit);

        if ($ownedAudit->status !== Audit::STATUS_COMPLETED) {
            $actionLogger->log(
                $request->user(),
                ActionLog::ACTION_RECOMMENDATION_REQUESTED,
                $ownedAudit,
                ActionLog::STATUS_FAILURE,
                ['audit_id' => $ownedAudit->id],
            );

            return response()->json([
                'message' => 'AI recommendations are only available after the audit is completed.',
            ], 409);
        }

        $lock = Cache::lock(
            'ai_recommendation:user:'.$request->user()->getAuthIdentifier().':lock',
            self::GENERATION_LOCK_SECONDS,
        );

        if (! $lock->get()) {
            return response()->json([
                'message' => 'AI recommendation generation is already in progress. Please try again shortly.',
            ], 429);
        }

        try {
            try {
                $result = $service->generate($ownedAudit);
            } catch (AiRecommendationException) {
                $actionLogger->log(
                    $request->user(),
                    ActionLog::ACTION_RECOMMENDATION_REQUESTED,
                    $ownedAudit,
                    ActionLog::STATUS_FAILURE,
                    ['audit_id' => $ownedAudit->id],
                );

                return response()->json([
                    'message' => 'AI recommendation service is unavailable.',
                ], 502);
            }

            $recommendation = $ownedAudit->aiRecommendations()->create($result);
            $actionLogger->log(
                $request->user(),
                ActionLog::ACTION_RECOMMENDATION_REQUESTED,
                $ownedAudit,
                metadata: [
                    'audit_id' => $ownedAudit->id,
                    'recommendation_id' => $recommendation->id,
                ],
            );

            return response()->json([
                'message' => 'AI recommendation generated successfully.',
                'recommendation' => $recommendation,
            ], 201);
        } finally {
            $lock->release();
        }
    }
}
