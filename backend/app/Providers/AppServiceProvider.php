<?php

namespace App\Providers;

use App\Models\User;
use App\Support\EmailAddress;
use App\Support\ProductionConfigurationValidator;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    private const PUBLIC_API_REQUESTS_PER_MINUTE = 120;

    private const ANALYTICS_PAGE_VIEWS_PER_IP_PER_MINUTE = 60;

    private const ANALYTICS_PAGE_VIEWS_PER_VISITOR_PER_MINUTE = 30;

    private const ANALYTICS_PAGE_VIEWS_PER_SESSION_PER_MINUTE = 60;

    private const ANALYTICS_PAGE_VIEWS_GLOBAL_PER_MINUTE = 5_000;

    private const PRE_AUTH_REQUESTS_PER_IP_PER_MINUTE = 600;

    private const AUTHENTICATED_API_REQUESTS_PER_MINUTE = 300;

    private const DASHBOARD_READ_REQUESTS_PER_MINUTE = 120;

    private const AUDIT_READ_REQUESTS_PER_MINUTE = 120;

    private const RECOMMENDATION_READ_REQUESTS_PER_MINUTE = 120;

    private const RECOMMENDATION_GENERATIONS_PER_AUDIT = 1;

    private const RECOMMENDATION_AUDIT_COOLDOWN_MINUTES = 5;

    private const RECOMMENDATION_GENERATIONS_PER_USER_PER_MINUTE = 10;

    private const RECOMMENDATION_GENERATIONS_PER_USER_PER_DAY = 50;

    private const RECOMMENDATION_GENERATIONS_GLOBAL_PER_MINUTE = 300;

    private const ADMIN_READ_REQUESTS_PER_MINUTE = 240;

    private const ADMIN_MUTATION_REQUESTS_PER_MINUTE = 30;

    private const ADMIN_EXPENSIVE_REQUESTS_PER_MINUTE = 60;

    private const LOGIN_ATTEMPTS_PER_EMAIL_AND_IP = 5;

    private const LOGIN_ATTEMPTS_PER_EMAIL = 10;

    private const LOGIN_ATTEMPTS_PER_IP = 20;

    private const VERIFICATION_ATTEMPTS_PER_EMAIL_AND_IP = 5;

    private const VERIFICATION_ATTEMPTS_PER_EMAIL = 10;

    private const VERIFICATION_ATTEMPTS_PER_IP = 20;

    private const FORGOT_PASSWORD_ATTEMPTS_PER_EMAIL_AND_IP = 5;

    private const FORGOT_PASSWORD_ATTEMPTS_PER_EMAIL = 10;

    private const FORGOT_PASSWORD_ATTEMPTS_PER_IP = 20;

    private const RESET_PASSWORD_ATTEMPTS_PER_EMAIL_AND_IP = 5;

    private const RESET_PASSWORD_ATTEMPTS_PER_EMAIL = 10;

    private const RESET_PASSWORD_ATTEMPTS_PER_IP = 20;

    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(ProductionConfigurationValidator $productionConfiguration): void
    {
        if ($this->app->environment('production')) {
            $productionConfiguration->validate();
        }

        VerifyEmail::toMailUsing(function (User $user, string $verificationUrl): MailMessage {
            $shortName = (string) config('app.short_name', 'AuditSEO');
            $expirationMinutes = (int) config('auth.verification.expire', 60);

            return $this->brandedMailMessage()
                ->subject("Verify your {$shortName} account")
                ->greeting('Hello,')
                ->line("Thank you for creating an {$shortName} account.")
                ->line('Please verify your email address to activate your account and access the platform.')
                ->action('Verify Email Address', $verificationUrl)
                ->line("This verification link will expire in {$expirationMinutes} minutes.")
                ->line('If you did not create this account, no action is required.')
                ->salutation("The {$shortName} Team");
        });

        ResetPassword::createUrlUsing(
            fn (User $user, string $token): string => $this->passwordResetUrl($user, $token),
        );

        ResetPassword::toMailUsing(function (User $user, string $token): MailMessage {
            $shortName = (string) config('app.short_name', 'AuditSEO');
            $broker = (string) config('auth.defaults.passwords', 'users');
            $expirationMinutes = (int) config("auth.passwords.{$broker}.expire", 60);

            return $this->brandedMailMessage()
                ->subject("Reset your {$shortName} password")
                ->greeting('Hello,')
                ->line("We received a request to reset the password for your {$shortName} account.")
                ->action('Reset Password', $this->passwordResetUrl($user, $token))
                ->line("This password reset link will expire in {$expirationMinutes} minutes.")
                ->line('If you did not request a password reset, you can safely ignore this email.')
                ->salutation("The {$shortName} Team");
        });

        RateLimiter::for('api-public', function (Request $request) {
            return Limit::perMinute(self::PUBLIC_API_REQUESTS_PER_MINUTE)
                ->by('api-public:'.$request->ip());
        });

        RateLimiter::for('analytics-page-view', function (Request $request) {
            $visitorId = (string) $request->input('visitor_id', 'missing');
            $sessionId = (string) $request->input('session_id', 'missing');

            return [
                Limit::perMinute(self::ANALYTICS_PAGE_VIEWS_PER_IP_PER_MINUTE)
                    ->by('analytics-page-view:ip:'.$request->ip()),
                Limit::perMinute(self::ANALYTICS_PAGE_VIEWS_PER_VISITOR_PER_MINUTE)
                    ->by('analytics-page-view:visitor:'.hash('sha256', $visitorId)),
                Limit::perMinute(self::ANALYTICS_PAGE_VIEWS_PER_SESSION_PER_MINUTE)
                    ->by('analytics-page-view:session:'.hash('sha256', $sessionId)),
                Limit::perMinute(self::ANALYTICS_PAGE_VIEWS_GLOBAL_PER_MINUTE)
                    ->by('analytics-page-view:global'),
            ];
        });

        RateLimiter::for('api-pre-auth', function (Request $request) {
            return Limit::perMinute(self::PRE_AUTH_REQUESTS_PER_IP_PER_MINUTE)
                ->by('api-pre-auth:ip:'.$request->ip());
        });

        RateLimiter::for('api-authenticated', function (Request $request) {
            $userId = $request->user()?->getAuthIdentifier();

            return Limit::perMinute(self::AUTHENTICATED_API_REQUESTS_PER_MINUTE)
                ->by($userId === null
                    ? 'api-authenticated:ip:'.$request->ip()
                    : 'api-authenticated:user:'.$userId);
        });

        RateLimiter::for('dashboard-read', function (Request $request) {
            $userId = $request->user()?->getAuthIdentifier();

            return Limit::perMinute(self::DASHBOARD_READ_REQUESTS_PER_MINUTE)
                ->by($userId === null
                    ? 'dashboard-read:ip:'.$request->ip()
                    : 'dashboard-read:user:'.$userId);
        });

        RateLimiter::for('audit-read', function (Request $request) {
            $userId = $request->user()?->getAuthIdentifier();

            return Limit::perMinute(self::AUDIT_READ_REQUESTS_PER_MINUTE)
                ->by($userId === null
                    ? 'audit-read:ip:'.$request->ip()
                    : 'audit-read:user:'.$userId);
        });

        RateLimiter::for('recommendation-read', function (Request $request) {
            $userId = $request->user()?->getAuthIdentifier();

            return Limit::perMinute(self::RECOMMENDATION_READ_REQUESTS_PER_MINUTE)
                ->by($userId === null
                    ? 'recommendation-read:ip:'.$request->ip()
                    : 'recommendation-read:user:'.$userId);
        });

        RateLimiter::for('recommendation-generate-audit', function (Request $request) {
            $userId = $request->user()?->getAuthIdentifier();
            $auditId = (string) ($request->route('audit') ?? 'unknown');
            $actor = $userId === null ? 'ip:'.$request->ip() : 'user:'.$userId;

            return Limit::perMinutes(
                self::RECOMMENDATION_AUDIT_COOLDOWN_MINUTES,
                self::RECOMMENDATION_GENERATIONS_PER_AUDIT,
            )->by('recommendation-generate:audit:'.$actor.':'.$auditId);
        });

        RateLimiter::for('recommendation-generate-user', function (Request $request) {
            $userId = $request->user()?->getAuthIdentifier();
            $actor = $userId === null ? 'ip:'.$request->ip() : 'user:'.$userId;

            return Limit::perMinute(self::RECOMMENDATION_GENERATIONS_PER_USER_PER_MINUTE)
                ->by('recommendation-generate:user-short:'.$actor);
        });

        RateLimiter::for('recommendation-generate-daily', function (Request $request) {
            $userId = $request->user()?->getAuthIdentifier();
            $actor = $userId === null ? 'ip:'.$request->ip() : 'user:'.$userId;

            return Limit::perDay(self::RECOMMENDATION_GENERATIONS_PER_USER_PER_DAY)
                ->by('recommendation-generate:user-daily:'.$actor);
        });

        RateLimiter::for('recommendation-generate-global', function () {
            return Limit::perMinute(self::RECOMMENDATION_GENERATIONS_GLOBAL_PER_MINUTE)
                ->by('recommendation-generate:global');
        });

        RateLimiter::for('admin-read', function (Request $request) {
            return Limit::perMinute(self::ADMIN_READ_REQUESTS_PER_MINUTE)
                ->by('admin-read:user:'.$request->user()->getAuthIdentifier());
        });

        RateLimiter::for('admin-mutation', function (Request $request) {
            return Limit::perMinute(self::ADMIN_MUTATION_REQUESTS_PER_MINUTE)
                ->by('admin-mutation:user:'.$request->user()->getAuthIdentifier());
        });

        RateLimiter::for('admin-expensive', function (Request $request) {
            return Limit::perMinute(self::ADMIN_EXPENSIVE_REQUESTS_PER_MINUTE)
                ->by('admin-expensive:user:'.$request->user()->getAuthIdentifier());
        });

        RateLimiter::for('login', function (Request $request) {
            $email = EmailAddress::canonicalize((string) $request->input('email'));
            $ip = (string) $request->ip();

            return [
                Limit::perMinute(self::LOGIN_ATTEMPTS_PER_EMAIL_AND_IP)
                    ->by('login-email-ip:'.sha1($email.'|'.$ip)),
                Limit::perMinute(self::LOGIN_ATTEMPTS_PER_EMAIL)
                    ->by('auth:login:email:'.hash('sha256', $email)),
                Limit::perMinute(self::LOGIN_ATTEMPTS_PER_IP)
                    ->by('login-ip:'.$ip),
            ];
        });

        RateLimiter::for('register', function (Request $request) {
            return Limit::perMinute(5)->by($request->ip());
        });

        RateLimiter::for('verification', function (Request $request) {
            $email = EmailAddress::canonicalize((string) $request->input('email'));
            $ip = (string) $request->ip();

            return [
                Limit::perMinute(self::VERIFICATION_ATTEMPTS_PER_EMAIL_AND_IP)
                    ->by('verification-email-ip:'.sha1($email.'|'.$ip)),
                Limit::perMinute(self::VERIFICATION_ATTEMPTS_PER_EMAIL)
                    ->by('auth:verification:email:'.hash('sha256', $email)),
                Limit::perMinute(self::VERIFICATION_ATTEMPTS_PER_IP)
                    ->by('verification-ip:'.$ip),
            ];
        });

        RateLimiter::for('forgot-password', function (Request $request) {
            $email = EmailAddress::canonicalize((string) $request->input('email'));
            $ip = (string) $request->ip();

            return [
                Limit::perMinute(self::FORGOT_PASSWORD_ATTEMPTS_PER_EMAIL_AND_IP)
                    ->by('forgot-password:email-ip:'.sha1($email.'|'.$ip)),
                Limit::perMinute(self::FORGOT_PASSWORD_ATTEMPTS_PER_EMAIL)
                    ->by('forgot-password:email:'.hash('sha256', $email)),
                Limit::perMinute(self::FORGOT_PASSWORD_ATTEMPTS_PER_IP)
                    ->by('forgot-password:ip:'.$ip),
            ];
        });

        RateLimiter::for('reset-password', function (Request $request) {
            $email = EmailAddress::canonicalize((string) $request->input('email'));
            $ip = (string) $request->ip();

            return [
                Limit::perMinute(self::RESET_PASSWORD_ATTEMPTS_PER_EMAIL_AND_IP)
                    ->by('reset-password:email-ip:'.sha1($email.'|'.$ip)),
                Limit::perMinute(self::RESET_PASSWORD_ATTEMPTS_PER_EMAIL)
                    ->by('reset-password:email:'.hash('sha256', $email)),
                Limit::perMinute(self::RESET_PASSWORD_ATTEMPTS_PER_IP)
                    ->by('reset-password:ip:'.$ip),
            ];
        });
    }

    private function brandedMailMessage(): MailMessage
    {
        return (new MailMessage)->from(
            (string) config('mail.from.address'),
            (string) config('mail.from.name'),
        );
    }

    private function passwordResetUrl(User $user, string $token): string
    {
        $frontendUrl = rtrim(
            (string) (config('services.frontend.url') ?: 'http://localhost:5173'),
            '/',
        );
        $query = http_build_query([
            'token' => $token,
            'email' => $user->getEmailForPasswordReset(),
        ], '', '&', PHP_QUERY_RFC3986);

        return $frontendUrl.'/reset-password?'.$query;
    }
}
