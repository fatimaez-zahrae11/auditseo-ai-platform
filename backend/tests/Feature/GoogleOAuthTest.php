<?php

namespace Tests\Feature;

use App\Models\ActionLog;
use App\Models\AuthAuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;
use Mockery;
use Tests\TestCase;

class GoogleOAuthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.google.client_id' => 'google-client-id.apps.googleusercontent.com',
            'services.google.client_secret' => 'test-client-secret',
            'services.google.redirect' => 'http://localhost:8000/api/auth/google/callback',
            'services.frontend.url' => 'http://localhost:5173',
        ]);
    }

    public function test_redirect_returns_a_google_authorization_url_without_secrets(): void
    {
        $response = $this->getJson('/api/auth/google/redirect')
            ->assertOk()
            ->assertJsonStructure(['url']);

        $url = $response->json('url');
        $this->assertIsString($url);
        $this->assertStringStartsWith('https://accounts.google.com/o/oauth2/auth?', $url);
        $this->assertStringContainsString('state=', $url);
        $this->assertStringNotContainsString('test-client-secret', $response->getContent());
        $this->assertDatabaseHas('auth_audit_logs', [
            'event' => AuthAuditLog::EVENT_GOOGLE_OAUTH_REDIRECT,
            'status' => AuthAuditLog::STATUS_SUCCESS,
        ]);
    }

    public function test_missing_google_configuration_returns_a_safe_service_unavailable_response(): void
    {
        config(['services.google.client_secret' => null]);

        $this->getJson('/api/auth/google/redirect')
            ->assertStatus(503)
            ->assertExactJson(['message' => 'Google sign-in is temporarily unavailable.']);
    }

    public function test_callback_creates_a_verified_regular_user(): void
    {
        $code = $this->completeCallback();

        $user = User::query()->where('email', 'oauth@example.com')->sole();
        $this->assertSame(User::ROLE_USER, $user->role);
        $this->assertTrue($user->is_active);
        $this->assertTrue($user->hasVerifiedEmail());
        $this->assertSame('google-user-123', $user->google_id);
        $this->assertNotSame('', $user->password);
        $this->assertSame(64, strlen($code));
    }

    public function test_callback_links_an_existing_user_by_verified_email(): void
    {
        $user = User::factory()->unverified()->create([
            'email' => 'oauth@example.com',
            'google_id' => null,
        ]);

        $this->completeCallback();

        $user->refresh();
        $this->assertSame('google-user-123', $user->google_id);
        $this->assertTrue($user->hasVerifiedEmail());
        $this->assertDatabaseHas('action_logs', [
            'actor_user_id' => $user->id,
            'action' => ActionLog::ACTION_GOOGLE_OAUTH_ACCOUNT_LINKED,
            'status' => ActionLog::STATUS_SUCCESS,
        ]);
    }

    public function test_callback_never_creates_an_admin_for_a_new_google_user(): void
    {
        $this->completeCallback([
            'name' => 'admin',
            'email' => 'new-admin@example.com',
        ]);

        $user = User::query()->where('email', 'new-admin@example.com')->sole();
        $this->assertSame(User::ROLE_USER, $user->role);
        $this->assertFalse($user->isAdmin());
    }

    public function test_existing_admin_keeps_the_admin_role_when_linked_by_email(): void
    {
        $admin = User::factory()->create([
            'email' => 'oauth@example.com',
            'role' => User::ROLE_ADMIN,
        ]);

        $this->completeCallback();

        $admin->refresh();
        $this->assertTrue($admin->isAdmin());
        $this->assertSame('google-user-123', $admin->google_id);
    }

    public function test_inactive_user_cannot_log_in_or_be_linked(): void
    {
        $user = User::factory()->create([
            'email' => 'oauth@example.com',
            'is_active' => false,
            'google_id' => null,
        ]);
        $state = $this->validState();
        $this->mockGoogleUser();

        $this->getJson('/api/auth/google/callback?'.http_build_query([
            'state' => $state,
            'code' => 'provider-authorization-code',
        ]))
            ->assertForbidden()
            ->assertExactJson([
                'message' => 'This account is disabled. Please contact an administrator.',
            ]);

        $this->assertNull($user->fresh()->google_id);
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_exchange_issues_an_expiring_sanctum_bearer_token(): void
    {
        $this->travelTo(now()->startOfSecond());
        $code = $this->completeCallback();

        $response = $this->postJson('/api/auth/google/exchange', ['code' => $code])
            ->assertOk()
            ->assertJsonStructure(['message', 'user' => ['id', 'name', 'email', 'role'], 'token'])
            ->assertJsonMissingPath('user.google_id')
            ->assertJsonMissingPath('user.password');

        $user = User::query()->where('email', 'oauth@example.com')->sole();
        $accessToken = $user->tokens()->sole();
        $this->assertNotEmpty($response->json('token'));
        $this->assertTrue($accessToken->expires_at->equalTo(now()->addDay()));
        $this->assertDatabaseHas('auth_audit_logs', [
            'user_id' => $user->id,
            'event' => AuthAuditLog::EVENT_GOOGLE_OAUTH_LOGIN,
            'status' => AuthAuditLog::STATUS_SUCCESS,
        ]);
    }

    public function test_exchange_code_is_single_use(): void
    {
        $code = $this->completeCallback();

        $this->postJson('/api/auth/google/exchange', ['code' => $code])->assertOk();
        $this->postJson('/api/auth/google/exchange', ['code' => $code])
            ->assertUnprocessable()
            ->assertExactJson(['message' => 'The Google sign-in code is invalid or has expired.']);
    }

    public function test_invalid_or_expired_exchange_code_returns_a_safe_error(): void
    {
        $user = User::factory()->create();
        $expiredCode = str_repeat('e', 64);
        Cache::put(
            'oauth:google:exchange:'.hash('sha256', $expiredCode),
            ['user_id' => $user->id],
            now()->subSecond(),
        );

        foreach ([str_repeat('x', 64), 'invalid', $expiredCode] as $code) {
            $this->postJson('/api/auth/google/exchange', ['code' => $code])
                ->assertUnprocessable()
                ->assertExactJson(['message' => 'The Google sign-in code is invalid or has expired.']);
        }
    }

    public function test_invalid_state_is_rejected_before_contacting_google(): void
    {
        Socialite::shouldReceive('driver')->never();

        $this->getJson('/api/auth/google/callback?state=invalid&code=provider-code')
            ->assertUnprocessable()
            ->assertExactJson(['message' => 'Google sign-in could not be completed. Please try again.']);

        $this->assertDatabaseCount('users', 0);
    }

    public function test_oauth_responses_do_not_expose_provider_credentials_or_codes(): void
    {
        $providerCode = 'provider-authorization-code-sensitive';
        $code = $this->completeCallback([
            'token' => 'google-access-token-sensitive',
            'refreshToken' => 'google-refresh-token-sensitive',
        ], $providerCode);

        $response = $this->postJson('/api/auth/google/exchange', ['code' => $code])->assertOk();
        $content = $response->getContent();

        $this->assertStringNotContainsString('test-client-secret', $content);
        $this->assertStringNotContainsString($providerCode, $content);
        $this->assertStringNotContainsString('google-access-token-sensitive', $content);
        $this->assertStringNotContainsString('google-refresh-token-sensitive', $content);

        foreach (AuthAuditLog::all() as $log) {
            $serialized = $log->toJson();
            $this->assertStringNotContainsString($providerCode, $serialized);
            $this->assertStringNotContainsString('google-access-token-sensitive', $serialized);
            $this->assertStringNotContainsString('google-refresh-token-sensitive', $serialized);
        }
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function completeCallback(array $overrides = [], string $providerCode = 'provider-authorization-code'): string
    {
        $state = $this->validState();
        $this->mockGoogleUser($overrides);

        $response = $this->get('/api/auth/google/callback?'.http_build_query([
            'state' => $state,
            'code' => $providerCode,
        ]))->assertRedirect();

        parse_str((string) parse_url((string) $response->headers->get('Location'), PHP_URL_QUERY), $query);
        $this->assertArrayHasKey('code', $query);

        return (string) $query['code'];
    }

    private function validState(): string
    {
        $state = str_repeat('s', 64);
        Cache::put('oauth:google:state:'.hash('sha256', $state), true, now()->addMinutes(5));

        return $state;
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function mockGoogleUser(array $overrides = []): void
    {
        $googleUser = SocialiteUser::fake(array_merge([
            'id' => 'google-user-123',
            'name' => 'OAuth User',
            'email' => 'oauth@example.com',
            'email_verified' => true,
            'verified_email' => true,
        ], $overrides));
        $provider = Mockery::mock();
        $provider->shouldReceive('stateless')->once()->andReturnSelf();
        $provider->shouldReceive('user')->once()->andReturn($googleUser);

        Socialite::shouldReceive('driver')->once()->with('google')->andReturn($provider);
    }
}
