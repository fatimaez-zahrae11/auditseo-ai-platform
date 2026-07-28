<?php

namespace App\Providers;

use App\Support\EmailAddress;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    private const LOGIN_ATTEMPTS_PER_EMAIL_AND_IP = 5;

    private const LOGIN_ATTEMPTS_PER_EMAIL = 10;

    private const LOGIN_ATTEMPTS_PER_IP = 20;

    private const VERIFICATION_ATTEMPTS_PER_EMAIL_AND_IP = 5;

    private const VERIFICATION_ATTEMPTS_PER_EMAIL = 10;

    private const VERIFICATION_ATTEMPTS_PER_IP = 20;

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
    public function boot(): void
    {
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
    }
}
