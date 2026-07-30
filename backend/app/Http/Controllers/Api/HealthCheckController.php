<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Throwable;

class HealthCheckController extends Controller
{
    public function index(): JsonResponse
    {
        try {
            DB::select('select 1');
        } catch (Throwable) {
            return response()->json([
                'status' => 'degraded',
                'app' => 'AuditSEO API',
                'database' => 'error',
            ], 503);
        }

        return response()->json([
            'status' => 'ok',
            'app' => 'AuditSEO API',
            'database' => 'ok',
        ]);
    }
}
