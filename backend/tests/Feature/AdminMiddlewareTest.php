<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminMiddlewareTest extends TestCase
{
    use RefreshDatabase;

    private const TEST_ROUTE = '/api/admin/middleware-test';

    protected function setUp(): void
    {
        parent::setUp();

        Route::middleware(['auth:sanctum', 'admin'])
            ->get(self::TEST_ROUTE, fn () => response()->json([
                'message' => 'Admin access granted',
            ]));
    }

    public function test_unauthenticated_request_to_an_admin_route_returns_401(): void
    {
        $this->getJson(self::TEST_ROUTE)
            ->assertUnauthorized()
            ->assertExactJson([
                'message' => 'Unauthenticated.',
            ]);
    }

    public function test_authenticated_non_admin_user_gets_403(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->getJson(self::TEST_ROUTE)
            ->assertForbidden()
            ->assertExactJson([
                'message' => 'Forbidden',
            ]);
    }

    public function test_authenticated_admin_user_can_access_the_route(): void
    {
        $admin = User::factory()->create();
        $admin->role = User::ROLE_ADMIN;
        $admin->save();

        Sanctum::actingAs($admin);

        $this->getJson(self::TEST_ROUTE)
            ->assertOk()
            ->assertExactJson([
                'message' => 'Admin access granted',
            ]);
    }
}
