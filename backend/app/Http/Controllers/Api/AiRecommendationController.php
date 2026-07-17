<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\AiRecommendationException;
use App\Http\Controllers\Controller;
use App\Models\Audit;
use App\Services\Ai\AiRecommendationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AiRecommendationController extends Controller
{
    public function store(
        Request $request,
        int $audit,
        AiRecommendationService $service,
    ): JsonResponse {
        $ownedAudit = Audit::query()
            ->with(['domain', 'issues'])
            ->whereHas('domain', fn ($query) => $query->where('user_id', $request->user()->id))
            ->findOrFail($audit);

        try {
            $result = $service->generate($ownedAudit);
        } catch (AiRecommendationException) {
            return response()->json([
                'message' => 'AI recommendation service is unavailable.',
            ], 502);
        }

        $recommendation = $ownedAudit->aiRecommendations()->create($result);

        return response()->json([
            'message' => 'AI recommendation generated successfully.',
            'recommendation' => $recommendation,
        ], 201);
    }
}
