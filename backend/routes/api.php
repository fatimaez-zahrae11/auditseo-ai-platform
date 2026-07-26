<?php

use App\Http\Controllers\Api\AiRecommendationController;
use App\Http\Controllers\Api\AuditController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\HealthCheckController;
use Illuminate\Support\Facades\Route;

Route::get('/health', [HealthCheckController::class, 'index']);

Route::post('/register', [AuthController::class, 'register'])
    ->middleware('throttle:register');
Route::post('/login', [AuthController::class, 'login'])
    ->middleware('throttle:login');

Route::middleware(['auth:sanctum', 'throttle:30,1'])->group(function () {
    Route::get('/me', [AuthController::class, 'me']);
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::post('/logout-all', [AuthController::class, 'logoutAll']);
    Route::get('/dashboard', [DashboardController::class, 'index']);

    Route::post('/audits', [AuditController::class, 'store'])
        ->withoutMiddleware('throttle:30,1')
        ->middleware('throttle:10,60');
    Route::get('/audits', [AuditController::class, 'index']);
    Route::get('/audits/{id}', [AuditController::class, 'show']);
    Route::get('/audits/{audit}/recommendations', [AiRecommendationController::class, 'index']);
    Route::post('/audits/{audit}/recommendations', [AiRecommendationController::class, 'store'])
        ->withoutMiddleware('throttle:30,1')
        ->middleware('throttle:5,1');
});
