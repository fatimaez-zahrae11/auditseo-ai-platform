<?php

use App\Http\Controllers\Api\AiRecommendationController;
use App\Http\Controllers\Api\AuditController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\HealthCheckController;
use Illuminate\Support\Facades\Route;

Route::get('/health', [HealthCheckController::class, 'index']);

Route::middleware('throttle:5,1')->group(function () {
    Route::post('/register', [AuthController::class, 'register']);
    Route::post('/login', [AuthController::class, 'login']);
});

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/me', [AuthController::class, 'me']);
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/dashboard', [DashboardController::class, 'index']);

    Route::post('/audits', [AuditController::class, 'store']);
    Route::get('/audits', [AuditController::class, 'index']);
    Route::get('/audits/{id}', [AuditController::class, 'show']);
    Route::get('/audits/{audit}/recommendations', [AiRecommendationController::class, 'index']);
    Route::post('/audits/{audit}/recommendations', [AiRecommendationController::class, 'store'])
        ->middleware('throttle:5,1');
});
