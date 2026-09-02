<?php

namespace Tests\Feature;

use App\Support\ProductionConfigurationValidator;
use RuntimeException;
use Tests\TestCase;

class ProductionConfigurationValidatorTest extends TestCase
{
    public function test_complete_secure_production_configuration_is_accepted(): void
    {
        $this->setValidProductionConfiguration();

        $this->app->make(ProductionConfigurationValidator::class)->validate();

        $this->assertTrue(true);
    }

    public function test_unsafe_or_incomplete_production_configuration_is_rejected_without_secret_values(): void
    {
        $this->setValidProductionConfiguration();
        config([
            'app.debug' => true,
            'app.url' => 'http://api.example.test',
            'services.frontend.url' => 'http://frontend.example.test',
            'cors.allowed_origins' => ['*'],
            'mail.default' => 'log',
            'mail.from.address' => 'no-reply@example.test',
            'services.google.client_id' => '',
            'services.google.client_secret' => 'google-client-secret-value',
            'services.google.redirect' => 'http://api.example.test/api/auth/google/callback',
            'database.default' => 'sqlite',
            'cache.default' => 'array',
            'cache.limiter' => 'array',
            'queue.default' => 'sync',
            'database.redis.client' => '',
            'database.redis.default.host' => '',
            'database.redis.default.url' => null,
            'database.redis.default.port' => null,
            'sanctum.expiration' => 0,
            'services.ai.provider' => 'provider-name',
            'services.ai.api_key' => 'ai-api-secret-value',
            'services.ai.base_url' => '',
            'services.ai.model' => '',
        ]);

        try {
            $this->app->make(ProductionConfigurationValidator::class)->validate();
            $this->fail('Unsafe production configuration was accepted.');
        } catch (RuntimeException $exception) {
            foreach ([
                'APP_DEBUG',
                'APP_URL',
                'FRONTEND_URL',
                'CORS_ALLOWED_ORIGINS',
                'MAIL_MAILER',
                'MAIL_FROM_ADDRESS',
                'GOOGLE_CLIENT_ID',
                'GOOGLE_REDIRECT_URI',
                'DB_CONNECTION',
                'CACHE_STORE',
                'CACHE_LIMITER',
                'QUEUE_CONNECTION',
                'REDIS_CLIENT',
                'REDIS_URL or REDIS_HOST',
                'REDIS_PORT',
                'SANCTUM_EXPIRATION',
                'AI provider settings',
            ] as $configurationName) {
                $this->assertStringContainsString($configurationName, $exception->getMessage());
            }

            $this->assertStringNotContainsString('google-client-secret-value', $exception->getMessage());
            $this->assertStringNotContainsString('ai-api-secret-value', $exception->getMessage());
        }
    }

    public function test_resend_and_ai_configuration_are_validated_when_enabled(): void
    {
        $this->setValidProductionConfiguration();
        config([
            'services.resend.key' => '',
            'services.ai.provider' => 'provider-name',
            'services.ai.base_url' => 'http://ai.example.test/v1',
            'services.ai.allowed_hosts' => ['different.example.test'],
            'services.ai.model' => 'model-name',
            'services.ai.api_key' => 'ai-api-secret-value',
        ]);

        try {
            $this->app->make(ProductionConfigurationValidator::class)->validate();
            $this->fail('Incomplete provider configuration was accepted.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('RESEND_API_KEY', $exception->getMessage());
            $this->assertStringContainsString('AI_BASE_URL', $exception->getMessage());
            $this->assertStringNotContainsString('ai-api-secret-value', $exception->getMessage());
        }
    }

    private function setValidProductionConfiguration(): void
    {
        config([
            'app.debug' => false,
            'app.key' => 'base64:production-placeholder-key',
            'app.url' => 'https://api.production.example',
            'services.frontend.url' => 'https://production.example',
            'cors.allowed_origins' => ['https://production.example'],
            'mail.default' => 'resend',
            'mail.from.address' => 'no-reply@auditseo.dev',
            'services.resend.key' => 'resend-placeholder-key',
            'services.google.client_id' => 'google-client-id',
            'services.google.client_secret' => 'google-client-secret',
            'services.google.redirect' => 'https://api.production.example/api/auth/google/callback',
            'database.default' => 'pgsql',
            'cache.default' => 'redis',
            'cache.limiter' => 'redis',
            'queue.default' => 'redis',
            'queue.connections.redis.driver' => 'redis',
            'database.redis.client' => 'predis',
            'database.redis.default.host' => '127.0.0.1',
            'database.redis.default.port' => 6379,
            'sanctum.expiration' => 1440,
            'services.ai.provider' => '',
            'services.ai.base_url' => '',
            'services.ai.allowed_hosts' => [],
            'services.ai.chat_endpoint' => '/chat/completions',
            'services.ai.model' => '',
            'services.ai.api_key' => '',
        ]);
    }
}
