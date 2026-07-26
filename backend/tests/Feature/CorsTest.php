<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class CorsTest extends TestCase
{
    private const FRONTEND_ORIGIN = 'https://frontend.example.test';

    public function test_cors_configuration_is_restricted_to_api_routes_and_named_origins(): void
    {
        $this->assertSame(['api/*'], config('cors.paths'));
        $this->assertNotContains('*', config('cors.allowed_origins'));
        $this->assertFalse(config('cors.supports_credentials'));
    }

    public function test_configured_frontend_origin_is_allowed(): void
    {
        Config::set('cors.allowed_origins', [self::FRONTEND_ORIGIN]);

        $this->getJson('/api/health', [
            'Origin' => self::FRONTEND_ORIGIN,
        ])
            ->assertOk()
            ->assertHeader('Access-Control-Allow-Origin', self::FRONTEND_ORIGIN)
            ->assertJsonPath('status', 'ok');
    }

    public function test_unrelated_origin_is_not_allowed(): void
    {
        Config::set('cors.allowed_origins', [self::FRONTEND_ORIGIN]);

        $unrelatedOrigin = 'https://unrelated.example.test';

        $response = $this->getJson('/api/health', [
            'Origin' => $unrelatedOrigin,
        ]);

        $response
            ->assertOk()
            ->assertHeader('Access-Control-Allow-Origin', self::FRONTEND_ORIGIN)
            ->assertJsonPath('status', 'ok');

        $this->assertNotSame(
            $unrelatedOrigin,
            $response->headers->get('Access-Control-Allow-Origin'),
        );
    }

    public function test_api_routes_still_respond_without_an_origin_header(): void
    {
        $this->getJson('/api/health')
            ->assertOk()
            ->assertJsonPath('status', 'ok');
    }
}
