<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreWebAnalyticsPageViewRequest;
use App\Services\WebAnalyticsService;
use Illuminate\Http\Response;

class AnalyticsController extends Controller
{
    public function pageView(
        StoreWebAnalyticsPageViewRequest $request,
        WebAnalyticsService $analytics,
    ): Response {
        // This endpoint is intentionally anonymous. Bearer credentials are ignored.
        $analytics->recordPageView($request->validated());

        return response()->noContent();
    }
}
