<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

class HealthCheckController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json([
            'status' => 'ok',
            'message' => 'AuditSEO backend API is running.',
            'timestamp' => now()->toISOString(),
        ]);
    }
}