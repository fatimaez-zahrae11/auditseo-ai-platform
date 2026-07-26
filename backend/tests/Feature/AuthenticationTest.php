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
        $this->assertNotNull($user->tokens()->firstOrFail()->expires_at);
    }

    public function test_a_user_can_login_with_valid_credentials(): void
    {
        $user = User::factory()->create([
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
        $this->assertNotNull($user->tokens()->firstOrFail()->expires_at);
    }

    public function test_login_revokes_the_users_old_tokens(): void
    {
        $user = User::factory()->create([
            'email' => 'test@example.com',
            'password' => Hash::make('Password1'),
        ]);
        $firstOldToken = $user->createToken('old-token-1')->accessToken;
        $secondOldToken = $user->createToken('old-token-2')->accessToken;

        $this->postJson('/api/login', [
            'email' => 'test@example.com',
            'password' => 'Password1',
        ])->assertOk();

        $this->assertDatabaseCount('personal_access_tokens', 1);
        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $firstOldToken->id]);
        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $secondOldToken->id]);
        $this->assertNotNull($user->tokens()->firstOrFail()->expires_at);
    }

    public function test_login_returns_the_same_error_for_an_invalid_password_and_unknown_email(): void
    {
        User::factory()->create([
            'email' => 'test@example.com',
            'password' => Hash::make('Password1'),
        ]);

        $invalidPasswordResponse = $this->postJson('/api/login', [
            'email' => 'test@example.com',
            'password' => 'WrongPassword1',
        ]);

        $unknownEmailResponse = $this->postJson('/api/login', [
            'email' => 'unknown@example.com',
            'password' => 'WrongPassword1',
        ]);

        $invalidPasswordResponse
            ->assertUnprocessable()
            ->assertExactJson(['message' => 'Invalid credentials.']);

        $unknownEmailResponse
            ->assertUnprocessable()
            ->assertExactJson(['message' => 'Invalid credentials.']);

        $this->assertSame($invalidPasswordResponse->getContent(), $unknownEmailResponse->getContent());
        $this->assertDatabaseCount('personal_access_tokens', 0);
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
        $currentToken = $user->createToken('current-token');
        $otherToken = $user->createToken('other-token');

        $this->withToken($currentToken->plainTextToken)
            ->postJson('/api/logout')
            ->assertOk()
            ->assertJson(['message' => 'Logout successful.']);

        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $currentToken->accessToken->id]);
        $this->assertDatabaseHas('personal_access_tokens', ['id' => $otherToken->accessToken->id]);

        $this->app['auth']->forgetGuards();

        $this->withToken($currentToken->plainTextToken)
            ->getJson('/api/me')
            ->assertUnauthorized();

        $this->app['auth']->forgetGuards();

        $this->withToken($otherToken->plainTextToken)
            ->getJson('/api/me')
            ->assertOk();
    }

    public function test_logout_all_requires_authentication_and_revokes_every_token(): void
    {
        $this->postJson('/api/logout-all')->assertUnauthorized();

        $user = User::factory()->create();
        $firstToken = $user->createToken('first-token')->plainTextToken;
        $secondToken = $user->createToken('second-token')->plainTextToken;

        $this->withToken($firstToken)
            ->postJson('/api/logout-all')
            ->assertOk()
            ->assertExactJson([
                'message' => 'All sessions logged out successfully.',
            ]);

        $this->assertDatabaseCount('personal_access_tokens', 0);

        $this->app['auth']->forgetGuards();

        $this->withToken($firstToken)
            ->getJson('/api/me')
            ->assertUnauthorized();

        $this->app['auth']->forgetGuards();

        $this->withToken($secondToken)
            ->getJson('/api/me')
            ->assertUnauthorized();
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

    public function test_register_rate_limit_does_not_block_login(): void
    {
        User::factory()->create([
            'email' => 'login@example.com',
            'password' => Hash::make('Password1'),
        ]);

        foreach (range(1, 5) as $attempt) {
            $this->postJson('/api/register', [
                'name' => "Test User {$attempt}",
                'email' => "register{$attempt}@example.com",
                'password' => 'Password1',
            ])->assertCreated();
        }

        $this->postJson('/api/register', [
            'name' => 'Rate Limited User',
            'email' => 'register-limited@example.com',
            'password' => 'Password1',
        ])->assertTooManyRequests();

        $this->postJson('/api/login', [
            'email' => 'login@example.com',
            'password' => 'Password1',
        ])->assertOk();
    }

    public function test_login_rate_limit_does_not_block_register(): void
    {
        User::factory()->create([
            'email' => 'login@example.com',
            'password' => Hash::make('Password1'),
        ]);

        foreach (range(1, 5) as $attempt) {
            $this->postJson('/api/login', [
                'email' => 'login@example.com',
                'password' => "WrongPassword{$attempt}",
            ])->assertUnprocessable();
        }

        $this->postJson('/api/login', [
            'email' => 'login@example.com',
            'password' => 'WrongPassword6',
        ])->assertTooManyRequests();

        $this->postJson('/api/register', [
            'name' => 'New User',
            'email' => 'register@example.com',
            'password' => 'Password1',
        ])->assertCreated();
    }
}
