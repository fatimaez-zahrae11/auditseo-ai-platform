<?php

namespace App\Support;

use Illuminate\Contracts\Config\Repository;
use RuntimeException;

final class ProductionConfigurationValidator
{
    public function __construct(private readonly Repository $config) {}

    public function validate(): void
    {
        $errors = [];

        $this->reject($errors, (bool) $this->config->get('app.debug'), 'APP_DEBUG must be false.');
        $this->reject($errors, $this->string('app.key') === '', 'APP_KEY must be configured.');

        $appUrl = $this->string('app.url');
        $frontendUrl = $this->string('services.frontend.url');
        $this->reject($errors, ! $this->isHttpsUrl($appUrl, true), 'APP_URL must be an HTTPS origin.');
        $this->reject($errors, ! $this->isHttpsUrl($frontendUrl, true), 'FRONTEND_URL must be an HTTPS origin.');

        $corsOrigins = $this->config->get('cors.allowed_origins', []);
        $validCors = is_array($corsOrigins)
            && count($corsOrigins) === 1
            && $this->isHttpsUrl((string) ($corsOrigins[0] ?? ''), true)
            && rtrim((string) ($corsOrigins[0] ?? ''), '/') === rtrim($frontendUrl, '/');
        $this->reject(
            $errors,
            ! $validCors || in_array('*', is_array($corsOrigins) ? $corsOrigins : [], true),
            'CORS_ALLOWED_ORIGINS must contain only FRONTEND_URL as an HTTPS origin.',
        );

        $mailer = $this->string('mail.default');
        $this->reject($errors, in_array($mailer, ['', 'log', 'array'], true), 'MAIL_MAILER must use a production transport.');
        $this->reject(
            $errors,
            $mailer === 'resend' && $this->string('services.resend.key') === '',
            'RESEND_API_KEY must be configured when MAIL_MAILER is resend.',
        );

        $fromAddress = strtolower($this->string('mail.from.address'));
        $fromDomain = strrchr($fromAddress, '@');
        $fromDomain = $fromDomain === false ? '' : substr($fromDomain, 1);
        $reservedFromDomain = in_array($fromDomain, ['example.com', 'example.net', 'example.org'], true)
            || str_ends_with($fromDomain, '.example')
            || str_ends_with($fromDomain, '.invalid')
            || str_ends_with($fromDomain, '.localhost')
            || str_ends_with($fromDomain, '.test');
        $this->reject(
            $errors,
            filter_var($fromAddress, FILTER_VALIDATE_EMAIL) === false || $reservedFromDomain,
            'MAIL_FROM_ADDRESS must use a real verified sender domain.',
        );

        $googleRedirect = $this->string('services.google.redirect');
        foreach (['client_id' => 'GOOGLE_CLIENT_ID', 'client_secret' => 'GOOGLE_CLIENT_SECRET'] as $key => $name) {
            $this->reject($errors, $this->string("services.google.{$key}") === '', "{$name} must be configured.");
        }
        $this->reject(
            $errors,
            ! $this->isHttpsUrl($googleRedirect)
                || parse_url($googleRedirect, PHP_URL_PATH) !== '/api/auth/google/callback'
                || $this->urlOrigin($googleRedirect) !== $this->urlOrigin($appUrl),
            'GOOGLE_REDIRECT_URI must use APP_URL and end in /api/auth/google/callback.',
        );

        $this->reject($errors, $this->string('database.default') !== 'pgsql', 'DB_CONNECTION must be pgsql.');

        $cacheStore = $this->string('cache.default');
        $cacheLimiter = $this->string('cache.limiter');
        $queueConnection = $this->string('queue.default');
        $this->reject($errors, in_array($cacheStore, ['', 'array'], true), 'CACHE_STORE must use a persistent production store.');
        $this->reject($errors, in_array($cacheLimiter, ['', 'array'], true), 'CACHE_LIMITER must use a shared production store.');
        $this->reject($errors, $queueConnection !== 'redis', 'QUEUE_CONNECTION must be redis.');
        $this->reject(
            $errors,
            $this->string('queue.connections.redis.driver') !== 'redis',
            'The Redis queue connection must use the redis driver.',
        );

        $redisUrl = $this->string('database.redis.default.url');
        $redisHost = $this->string('database.redis.default.host');
        $redisPort = (int) $this->config->get('database.redis.default.port', 0);
        $this->reject($errors, $this->string('database.redis.client') === '', 'REDIS_CLIENT must be configured.');
        $this->reject($errors, $redisUrl === '' && $redisHost === '', 'REDIS_URL or REDIS_HOST must be configured.');
        $this->reject($errors, $redisUrl === '' && $redisPort < 1, 'REDIS_PORT must be configured.');

        $sanctumExpiration = (int) $this->config->get('sanctum.expiration', 0);
        $this->reject($errors, $sanctumExpiration < 1, 'SANCTUM_EXPIRATION must be a positive number of minutes.');

        $aiKeys = [
            'provider',
            'base_url',
            'chat_endpoint',
            'model',
            'api_key',
        ];
        $aiEnabled = collect(['provider', 'base_url', 'model', 'api_key'])
            ->contains(fn (string $key): bool => $this->string("services.ai.{$key}") !== '');
        if ($aiEnabled) {
            foreach ($aiKeys as $key) {
                $this->reject(
                    $errors,
                    $this->string("services.ai.{$key}") === '',
                    'All AI provider settings must be configured when AI recommendations are enabled.',
                );
            }

            $allowedHosts = $this->config->get('services.ai.allowed_hosts', []);
            $baseHost = strtolower((string) parse_url($this->string('services.ai.base_url'), PHP_URL_HOST));
            $this->reject(
                $errors,
                ! $this->isHttpsUrl($this->string('services.ai.base_url'))
                    || ! is_array($allowedHosts)
                    || $baseHost === ''
                    || ! in_array($baseHost, $allowedHosts, true),
                'AI_BASE_URL must use HTTPS and its host must be listed in AI_ALLOWED_HOSTS.',
            );
        }

        if ($errors !== []) {
            throw new RuntimeException('Invalid production configuration: '.implode(' ', array_values(array_unique($errors))));
        }
    }

    /** @param list<string> $errors */
    private function reject(array &$errors, bool $condition, string $message): void
    {
        if ($condition) {
            $errors[] = $message;
        }
    }

    private function string(string $key): string
    {
        return trim((string) $this->config->get($key));
    }

    private function isHttpsUrl(string $url, bool $originOnly = false): bool
    {
        if (filter_var($url, FILTER_VALIDATE_URL) === false) {
            return false;
        }

        $parts = parse_url($url);
        if (! is_array($parts)
            || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
            || ($parts['host'] ?? '') === ''
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['query'])
            || isset($parts['fragment'])) {
            return false;
        }

        if (! $originOnly) {
            return true;
        }

        return ! isset($parts['path']) || in_array($parts['path'], ['', '/'], true);
    }

    private function urlOrigin(string $url): string
    {
        $parts = parse_url($url);
        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host'])) {
            return '';
        }

        $port = isset($parts['port']) ? ':'.$parts['port'] : '';

        return strtolower($parts['scheme'].'://'.$parts['host'].$port);
    }
}
