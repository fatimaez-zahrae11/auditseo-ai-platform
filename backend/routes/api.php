<?php

use App\Http\Controllers\Api\Admin\AdminActionLogController;
use App\Http\Controllers\Api\Admin\AdminAnalyticsController;
use App\Http\Controllers\Api\Admin\AdminAuditController;
use App\Http\Controllers\Api\Admin\AdminRecommendationController;
use App\Http\Controllers\Api\Admin\AdminSecurityController;
use App\Http\Controllers\Api\Admin\AdminSystemController;
use App\Http\Controllers\Api\Admin\AdminUserController;
use App\Http\Controllers\Api\AiRecommendationController;
use App\Http\Controllers\Api\AnalyticsController;
use App\Http\Controllers\Api\AuditController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\EmailVerificationController;
use App\Http\Controllers\Api\GoogleOAuthController;
use App\Http\Controllers\Api\HealthCheckController;
use App\Http\Middleware\ThrottlePreAuthentication;
use Illuminate\Support\Facades\Route;

$preAuthThrottle = ThrottlePreAuthentication::class.':api-pre-auth';

Route::middleware('throttle:api-public')->group(function () {
    Route::get('/health', [HealthCheckController::class, 'index']);

    Route::post('/register', [AuthController::class, 'register'])
        ->middleware('throttle:register');
    Route::post('/login', [AuthController::class, 'login'])
        ->middleware('throttle:login');
    Route::get('/auth/google/redirect', [GoogleOAuthController::class, 'redirect']);
    Route::get('/auth/google/callback', [GoogleOAuthController::class, 'callback']);
    Route::post('/auth/google/exchange', [GoogleOAuthController::class, 'exchange']);
    Route::get('/email/verify/{id}/{hash}', [EmailVerificationController::class, 'verify'])
        ->name('verification.verify');
    Route::post('/email/verification-notification', [EmailVerificationController::class, 'resend'])
        ->middleware('throttle:verification');
});

Route::post('/analytics/page-view', [AnalyticsController::class, 'pageView'])
    ->middleware('throttle:analytics-page-view');

Route::get('/health/readiness', [HealthCheckController::class, 'readiness'])
    ->middleware([
        $preAuthThrottle,
        'auth:sanctum',
        'active',
        'admin',
        'verified',
        'throttle:admin-read',
        'throttle:admin-expensive',
    ]);

// Admin middleware permits these routes to use global, rather than user-scoped, queries.
Route::prefix('admin')->middleware([$preAuthThrottle, 'auth:sanctum', 'active', 'admin'])->group(function () {
    Route::middleware('throttle:admin-read')->group(function () {
        Route::get('/action-logs', [AdminActionLogController::class, 'index']);
        Route::get('/audits', [AdminAuditController::class, 'index']);
        Route::get('/recommendations', [AdminRecommendationController::class, 'index']);
        Route::get('/users', [AdminUserController::class, 'index']);
        Route::get('/users/{user}/activity', [AdminUserController::class, 'activity']);

        Route::middleware('throttle:admin-expensive')->group(function () {
            Route::get('/analytics/overview', [AdminAnalyticsController::class, 'overview']);
            Route::get('/analytics/traffic', [AdminAnalyticsController::class, 'traffic']);
            Route::get('/analytics/web-traffic', [AdminAnalyticsController::class, 'webTraffic']);
            Route::get('/analytics/active-users', [AdminAnalyticsController::class, 'activeUsers']);
            Route::get('/analytics/heavy-users', [AdminAnalyticsController::class, 'heavyUsers']);
            Route::get('/security/ip-intelligence', [AdminSecurityController::class, 'ipIntelligence']);
            Route::get('/system/logs', [AdminSystemController::class, 'logs']);
            Route::get('/system/health-detailed', [AdminSystemController::class, 'healthDetailed']);
        });
    });

    Route::middleware('throttle:admin-mutation')->group(function () {
        Route::post('/users', [AdminUserController::class, 'store']);
        Route::patch('/users/{user}/deactivate', [AdminUserController::class, 'deactivate']);
        Route::patch('/users/{user}/reactivate', [AdminUserController::class, 'reactivate']);
    });
});

Route::middleware([
    $preAuthThrottle,
    'auth:sanctum',
    'active',
    'throttle:api-authenticated',
    'throttle:30,1',
])->group(function () {
    Route::get('/me', [AuthController::class, 'me']);
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::post('/logout-all', [AuthController::class, 'logoutAll']);

    Route::middleware('verified')->group(function () {
        Route::get('/dashboard', [DashboardController::class, 'index'])
            ->withoutMiddleware('throttle:30,1')
            ->middleware('throttle:dashboard-read');

        Route::post('/audits', [AuditController::class, 'store'])
            ->withoutMiddleware('throttle:30,1')
            ->middleware('throttle:10,60');
        Route::get('/audits', [AuditController::class, 'index'])
            ->withoutMiddleware('throttle:30,1')
            ->middleware('throttle:audit-read');
        Route::get('/audits/{id}', [AuditController::class, 'show'])
            ->withoutMiddleware('throttle:30,1')
            ->middleware('throttle:audit-read');
        Route::get('/audits/{audit}/recommendations', [AiRecommendationController::class, 'index'])
            ->withoutMiddleware('throttle:30,1')
            ->middleware('throttle:recommendation-read');
        Route::post('/audits/{audit}/recommendations', [AiRecommendationController::class, 'store'])
            ->withoutMiddleware('throttle:30,1')
            ->middleware([
                'throttle:recommendation-generate-audit',
                'throttle:recommendation-generate-user',
                'throttle:recommendation-generate-daily',
                'throttle:recommendation-generate-global',
            ]);
    });
});
