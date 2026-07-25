<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_user_can_register_and_receive_a_sanctum_token(): void
    {
        $response = $this->postJson('/api/register', [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'Password1',
        ]);

        $response
            ->assertCreated()
            ->assertJsonStructure(['message', 'user' => ['id', 'name', 'email'], 'token'])
            ->assertJsonMissingPath('user.password');

        $user = User::where('email', 'test@example.com')->firstOrFail();

        $this->assertTrue(Hash::check('Password1', $user->password));
        $this->assertNotSame('Password1', $user->password);
        $this->assertDatabaseCount('personal_access_tokens', 1);
    }

    public function test_a_user_can_login_with_valid_credentials(): void
    {
        User::factory()->create([
            'email' => 'test@example.com',
            'password' => Hash::make('Password1'),
        ]);

        $response = $this->postJson('/api/login', [
            'email' => 'test@example.com',
            'password' => 'Password1',
        ]);

        $response
            ->assertOk()
            ->assertJsonStructure(['message', 'user' => ['id', 'name', 'email'], 'token'])
            ->assertJsonMissingPath('user.password');

        $this->assertDatabaseCount('personal_access_tokens', 1);
    }

    public function test_login_rejects_invalid_credentials(): void
    {
        User::factory()->create([
            'email' => 'test@example.com',
            'password' => Hash::make('Password1'),
        ]);

        $this->postJson('/api/login', [
            'email' => 'test@example.com',
            'password' => 'WrongPassword1',
        ])->assertUnprocessable()->assertJson(['message' => 'Invalid credentials.']);
    }

    public function test_authentication_endpoints_validate_their_input(): void
    {
        $this->postJson('/api/register', [
            'email' => 'not-an-email',
            'password' => 'lowercase',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['name', 'email', 'password']);

        $this->postJson('/api/login', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['email', 'password']);

        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_registration_rejects_an_email_that_is_already_registered(): void
    {
        User::factory()->create(['email' => 'test@example.com']);

        $this->postJson('/api/register', [
            'name' => 'Duplicate User',
            'email' => 'test@example.com',
            'password' => 'Password1',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('email');

        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_me_requires_authentication_and_returns_the_authenticated_user(): void
    {
        $this->getJson('/api/me')->assertUnauthorized();

        $user = User::factory()->create();
        $token = $user->createToken('test-token')->plainTextToken;

        $this->withToken($token)
            ->getJson('/api/me')
            ->assertOk()
            ->assertJsonPath('user.id', $user->id)
            ->assertJsonMissingPath('user.password');
    }

    public function test_logout_requires_authentication_and_revokes_the_current_token(): void
    {
        $this->postJson('/api/logout')->assertUnauthorized();

        $user = User::factory()->create();
        $token = $user->createToken('test-token')->plainTextToken;

        $this->withToken($token)
            ->postJson('/api/logout')
            ->assertOk()
            ->assertJson(['message' => 'Logout successful.']);

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_register_is_rate_limited(): void
    {
        foreach (range(1, 5) as $attempt) {
            $this->postJson('/api/register', [
                'name' => "Test User {$attempt}",
                'email' => "test{$attempt}@example.com",
                'password' => 'Password1',
            ])->assertCreated();
        }

        $this->postJson('/api/register', [
            'name' => 'Rate Limited User',
            'email' => 'limited@example.com',
            'password' => 'Password1',
        ])->assertTooManyRequests();
    }

    public function test_login_is_rate_limited(): void
    {
        User::factory()->create([
            'email' => 'test@example.com',
            'password' => Hash::make('Password1'),
        ]);

        foreach (range(1, 5) as $attempt) {
            $this->postJson('/api/login', [
                'email' => 'test@example.com',
                'password' => "WrongPassword{$attempt}",
            ])->assertUnprocessable();
        }

        $this->postJson('/api/login', [
            'email' => 'test@example.com',
            'password' => 'WrongPassword6',
        ])->assertTooManyRequests();

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }
}
