<?php

namespace Tests\Feature;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class TrustedProxyTest extends TestCase
{
    public function test_api_requests_use_forwarded_headers_from_a_reverse_proxy(): void
    {
        Route::get('/api/test-trusted-proxy', function (Request $request) {
            return response()->json([
                'ip' => $request->ip(),
                'host' => $request->getHost(),
                'secure' => $request->isSecure(),
            ]);
        });

        $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1'])
            ->getJson('/api/test-trusted-proxy', [
                'X-Forwarded-For' => '203.0.113.25',
                'X-Forwarded-Host' => 'api.example.com',
                'X-Forwarded-Port' => '443',
                'X-Forwarded-Proto' => 'https',
            ])
            ->assertOk()
            ->assertExactJson([
                'ip' => '203.0.113.25',
                'host' => 'api.example.com',
                'secure' => true,
            ]);
    }
}
