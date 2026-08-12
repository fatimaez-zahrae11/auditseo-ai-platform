<?php

use App\Http\Controllers\Api\Admin\AdminAuditController;
use App\Http\Controllers\Api\Admin\AdminUserController;
use App\Http\Controllers\Api\AiRecommendationController;
use App\Http\Controllers\Api\AuditController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\EmailVerificationController;
use App\Http\Controllers\Api\HealthCheckController;
use Illuminate\Support\Facades\Route;

Route::middleware('throttle:api-public')->group(function () {
    Route::get('/health', [HealthCheckController::class, 'index']);

    Route::post('/register', [AuthController::class, 'register'])
        ->middleware('throttle:register');
    Route::post('/login', [AuthController::class, 'login'])
        ->middleware('throttle:login');
    Route::get('/email/verify/{id}/{hash}', [EmailVerificationController::class, 'verify'])
        ->name('verification.verify');
    Route::post('/email/verification-notification', [EmailVerificationController::class, 'resend'])
        ->middleware('throttle:verification');
});

// Admin middleware permits these routes to use global, rather than user-scoped, queries.
Route::prefix('admin')->middleware(['auth:sanctum', 'active', 'admin'])->group(function () {
    Route::get('/audits', [AdminAuditController::class, 'index']);
    Route::get('/users', [AdminUserController::class, 'index']);
    Route::post('/users', [AdminUserController::class, 'store']);
    Route::patch('/users/{user}/deactivate', [AdminUserController::class, 'deactivate']);
    Route::patch('/users/{user}/reactivate', [AdminUserController::class, 'reactivate']);
});

Route::middleware([
    'auth:sanctum',
    'active',
    'throttle:api-authenticated',
    'throttle:30,1',
])->group(function () {
    Route::get('/me', [AuthController::class, 'me']);
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::post('/logout-all', [AuthController::class, 'logoutAll']);

    Route::middleware('verified')->group(function () {
        Route::get('/health/readiness', [HealthCheckController::class, 'readiness']);
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
});
