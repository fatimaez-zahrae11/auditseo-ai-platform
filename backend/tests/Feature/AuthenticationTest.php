<?php

namespace Tests\Feature;

use App\Models\AuthAuditLog;
use App\Models\User;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_register_creates_an_unverified_user_and_sends_verification_without_a_token(): void
    {
        Notification::fake();

        $response = $this->postJson('/api/register', [
            'name' => 'Test User',
            'email' => ' Test@Example.COM ',
            'password' => 'Password1',
        ]);

        $response
            ->assertCreated()
            ->assertExactJson([
                'message' => 'Registration successful. Please verify your email before logging in.',
            ])
            ->assertJsonMissingPath('token');

        $user = User::where('email', 'test@example.com')->firstOrFail();

        $this->assertTrue(Hash::check('Password1', $user->password));
        $this->assertNotSame('Password1', $user->password);
        $this->assertFalse($user->hasVerifiedEmail());
        $this->assertDatabaseCount('personal_access_tokens', 0);
        Notification::assertSentTo($user, VerifyEmail::class);
    }

    public function test_a_user_can_login_with_valid_credentials(): void
    {
        $user = User::factory()->create([
            'email' => 'test@example.com',
            'password' => Hash::make('Password1'),
        ]);

        $response = $this->postJson('/api/login', [
            'email' => ' TEST@Example.COM ',
            'password' => 'Password1',
        ]);

        $response
            ->assertOk()
            ->assertJsonStructure(['message', 'user' => ['id', 'name', 'email'], 'token'])
            ->assertJsonMissingPath('user.password');

        $this->assertDatabaseCount('personal_access_tokens', 1);
        $this->assertNotNull($user->tokens()->firstOrFail()->expires_at);

        $auditLog = AuthAuditLog::sole();

        $this->assertSame($user->id, $auditLog->user_id);
        $this->assertSame('test@example.com', $auditLog->email);
        $this->assertSame(AuthAuditLog::EVENT_LOGIN, $auditLog->event);
        $this->assertSame(AuthAuditLog::STATUS_SUCCESS, $auditLog->status);
        $this->assertNotNull($auditLog->ip_address);
        $this->assertStringNotContainsString('Password1', $auditLog->toJson());
        $this->assertStringNotContainsString($response->json('token'), $auditLog->toJson());
    }

    public function test_unverified_users_cannot_login_or_receive_a_token(): void
    {
        $user = User::factory()->unverified()->create([
            'email' => 'unverified@example.com',
            'password' => Hash::make('Password1'),
        ]);

        $this->postJson('/api/login', [
            'email' => 'unverified@example.com',
            'password' => 'Password1',
        ])
            ->assertForbidden()
            ->assertExactJson([
                'message' => 'Email verification is required before login.',
            ]);

        $this->assertDatabaseCount('personal_access_tokens', 0);
        $this->assertDatabaseHas('auth_audit_logs', [
            'user_id' => $user->id,
            'event' => AuthAuditLog::EVENT_LOGIN,
            'status' => AuthAuditLog::STATUS_FAILED,
        ]);
    }

    public function test_signed_verification_route_marks_the_email_as_verified(): void
    {
        $user = User::factory()->unverified()->create();
        $verificationUrl = URL::temporarySignedRoute(
            'verification.verify',
            now()->addMinutes(60),
            [
                'id' => $user->id,
                'hash' => sha1($user->getEmailForVerification()),
            ],
        );

        $this->getJson($verificationUrl)
            ->assertOk()
            ->assertExactJson([
                'message' => 'Email verified successfully. You may now log in.',
            ]);

        $this->assertTrue($user->fresh()->hasVerifiedEmail());
    }

    public function test_verification_route_rejects_invalid_signatures_and_email_hashes(): void
    {
        $user = User::factory()->unverified()->create();
        $correctHash = sha1($user->getEmailForVerification());
        $unsignedUrl = route('verification.verify', [
            'id' => $user->id,
            'hash' => $correctHash,
        ]);
        $wrongHashUrl = URL::temporarySignedRoute(
            'verification.verify',
            now()->addMinutes(60),
            [
                'id' => $user->id,
                'hash' => sha1('wrong@example.com'),
            ],
        );

        foreach ([$unsignedUrl, $wrongHashUrl] as $verificationUrl) {
            $this->getJson($verificationUrl)
                ->assertForbidden()
                ->assertExactJson([
                    'message' => 'The verification link is invalid or has expired.',
                ]);
        }

        $this->assertFalse($user->fresh()->hasVerifiedEmail());
    }

    public function test_verification_notification_can_be_resent_without_revealing_account_existence(): void
    {
        Notification::fake();
        $user = User::factory()->unverified()->create([
            'email' => 'unverified@example.com',
        ]);

        $existingResponse = $this->postJson('/api/email/verification-notification', [
            'email' => ' UNVERIFIED@Example.COM ',
        ]);
        $unknownResponse = $this->postJson('/api/email/verification-notification', [
            'email' => 'unknown@example.com',
        ]);

        $existingResponse
            ->assertOk()
            ->assertExactJson([
                'message' => 'If the email is registered and unverified, a verification link has been sent.',
            ]);
        $unknownResponse
            ->assertOk()
            ->assertExactJson([
                'message' => 'If the email is registered and unverified, a verification link has been sent.',
            ]);

        $this->assertSame($existingResponse->getContent(), $unknownResponse->getContent());
        Notification::assertSentTo($user, VerifyEmail::class);
        Notification::assertCount(1);
    }

    public function test_verification_resend_is_rate_limited_for_the_same_email_and_ip(): void
    {
        Notification::fake();
        $ip = '198.51.100.40';
        $user = User::factory()->unverified()->create([
            'email' => 'unverified@example.com',
        ]);
        $genericResponse = [
            'message' => 'If the email is registered and unverified, a verification link has been sent.',
        ];

        foreach (range(1, 5) as $attempt) {
            $this->withServerVariables(['REMOTE_ADDR' => $ip])
                ->postJson('/api/email/verification-notification', [
                    'email' => $attempt % 2 === 0
                        ? ' UNVERIFIED@Example.COM '
                        : 'unverified@example.com',
                ])
                ->assertOk()
                ->assertExactJson($genericResponse);
        }

        $this->withServerVariables(['REMOTE_ADDR' => $ip])
            ->postJson('/api/email/verification-notification', [
                'email' => 'unverified@example.com',
            ])
            ->assertTooManyRequests();

        Notification::assertSentToTimes($user, VerifyEmail::class, 5);
    }

    public function test_verification_resend_is_rate_limited_when_one_ip_rotates_emails(): void
    {
        Notification::fake();
        $ip = '198.51.100.41';
        $genericResponse = [
            'message' => 'If the email is registered and unverified, a verification link has been sent.',
        ];

        foreach (range(1, 20) as $attempt) {
            $this->withServerVariables(['REMOTE_ADDR' => $ip])
                ->postJson('/api/email/verification-notification', [
                    'email' => "unknown{$attempt}@example.com",
                ])
                ->assertOk()
                ->assertExactJson($genericResponse);
        }

        $this->withServerVariables(['REMOTE_ADDR' => $ip])
            ->postJson('/api/email/verification-notification', [
                'email' => 'another-unknown@example.com',
            ])
            ->assertTooManyRequests();

        Notification::assertNothingSent();
    }

    public function test_verification_resend_ip_limits_are_independent(): void
    {
        Notification::fake();
        $limitedIp = '198.51.100.42';
        $independentIp = '198.51.100.43';
        $genericResponse = [
            'message' => 'If the email is registered and unverified, a verification link has been sent.',
        ];

        foreach (range(1, 20) as $attempt) {
            $this->withServerVariables(['REMOTE_ADDR' => $limitedIp])
                ->postJson('/api/email/verification-notification', [
                    'email' => "limited{$attempt}@example.com",
                ])
                ->assertOk()
                ->assertExactJson($genericResponse);
        }

        $this->withServerVariables(['REMOTE_ADDR' => $limitedIp])
            ->postJson('/api/email/verification-notification', [
                'email' => 'limited-again@example.com',
            ])
            ->assertTooManyRequests();

        $this->withServerVariables(['REMOTE_ADDR' => $independentIp])
            ->postJson('/api/email/verification-notification', [
                'email' => 'unknown@example.com',
            ])
            ->assertOk()
            ->assertExactJson($genericResponse);

        Notification::assertNothingSent();
    }

    public function test_sensitive_routes_reject_an_unverified_user_with_a_token(): void
    {
        $user = User::factory()->unverified()->create();
        $token = $user->createToken('unverified-token')->plainTextToken;

        foreach (['/api/dashboard', '/api/audits', '/api/audits/1'] as $uri) {
            $this->app['auth']->forgetGuards();

            $this->withToken($token)
                ->getJson($uri)
                ->assertForbidden()
                ->assertExactJson(['message' => 'Forbidden.']);
        }
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
            'email' => ' UNKNOWN@Example.COM ',
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
        $this->assertDatabaseHas('auth_audit_logs', [
            'user_id' => null,
            'email' => 'unknown@example.com',
            'event' => AuthAuditLog::EVENT_LOGIN,
            'status' => AuthAuditLog::STATUS_FAILED,
        ]);
        $this->assertDatabaseCount('auth_audit_logs', 2);

        foreach (AuthAuditLog::all() as $auditLog) {
            $this->assertStringNotContainsString('WrongPassword1', $auditLog->toJson());
            $this->assertArrayNotHasKey('password', $auditLog->getAttributes());
            $this->assertArrayNotHasKey('token', $auditLog->getAttributes());
        }
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
        Notification::fake();

        $this->postJson('/api/register', [
            'name' => 'Original User',
            'email' => 'User@Example.COM',
            'password' => 'Password1',
        ])->assertCreated();

        $this->postJson('/api/register', [
            'name' => 'Duplicate User',
            'email' => ' USER@example.com ',
            'password' => 'Password1',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('email');

        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseHas('users', ['email' => 'user@example.com']);
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_database_rejects_case_variant_email_duplicates(): void
    {
        User::factory()->create(['email' => 'database@example.com']);

        try {
            DB::table('users')->insert([
                'name' => 'Case Variant',
                'email' => 'DATABASE@EXAMPLE.COM',
                'password' => Hash::make('Password1'),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $this->fail('The database accepted a case-variant duplicate email.');
        } catch (QueryException) {
            $this->assertDatabaseCount('users', 1);
        }
    }

    public function test_me_requires_authentication_and_returns_the_authenticated_user(): void
    {
        $this->getJson('/api/me')->assertUnauthorized();

        $user = User::factory()->unverified()->create();
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

        $user = User::factory()->unverified()->create();
        $currentToken = $user->createToken('current-token');
        $otherToken = $user->createToken('other-token');

        $this->withToken($currentToken->plainTextToken)
            ->postJson('/api/logout')
            ->assertOk()
            ->assertJson(['message' => 'Logout successful.']);

        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $currentToken->accessToken->id]);
        $this->assertDatabaseHas('personal_access_tokens', ['id' => $otherToken->accessToken->id]);
        $this->assertDatabaseHas('auth_audit_logs', [
            'user_id' => $user->id,
            'email' => $user->email,
            'event' => AuthAuditLog::EVENT_LOGOUT,
            'status' => AuthAuditLog::STATUS_SUCCESS,
        ]);

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
        $this->assertDatabaseHas('auth_audit_logs', [
            'user_id' => $user->id,
            'email' => $user->email,
            'event' => AuthAuditLog::EVENT_LOGOUT_ALL,
            'status' => AuthAuditLog::STATUS_SUCCESS,
        ]);

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
        $this->assertDatabaseCount('auth_audit_logs', 5);
    }

    public function test_login_is_rate_limited_when_the_same_ip_rotates_email_addresses(): void
    {
        $ip = '198.51.100.10';

        foreach (range(1, 20) as $attempt) {
            $this->withServerVariables(['REMOTE_ADDR' => $ip])
                ->postJson('/api/login', [
                    'email' => "unknown{$attempt}@example.com",
                    'password' => 'WrongPassword1',
                ])
                ->assertUnprocessable()
                ->assertExactJson(['message' => 'Invalid credentials.']);
        }

        $this->withServerVariables(['REMOTE_ADDR' => $ip])
            ->postJson('/api/login', [
                'email' => 'another-unknown@example.com',
                'password' => 'WrongPassword1',
            ])
            ->assertTooManyRequests();

        $this->assertDatabaseCount('auth_audit_logs', 20);
    }

    public function test_login_rate_limits_are_independent_between_ip_addresses(): void
    {
        $user = User::factory()->create([
            'email' => 'test@example.com',
            'password' => Hash::make('Password1'),
        ]);

        foreach (range(1, 5) as $attempt) {
            $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.20'])
                ->postJson('/api/login', [
                    'email' => $user->email,
                    'password' => "WrongPassword{$attempt}",
                ])
                ->assertUnprocessable();
        }

        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.20'])
            ->postJson('/api/login', [
                'email' => $user->email,
                'password' => 'WrongPassword6',
            ])
            ->assertTooManyRequests();

        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.21'])
            ->postJson('/api/login', [
                'email' => $user->email,
                'password' => 'Password1',
            ])
            ->assertOk()
            ->assertJsonPath('message', 'Login successful.');

        $this->assertDatabaseCount('auth_audit_logs', 6);
        $this->assertDatabaseHas('auth_audit_logs', [
            'user_id' => $user->id,
            'event' => AuthAuditLog::EVENT_LOGIN,
            'ip_address' => '198.51.100.21',
            'status' => AuthAuditLog::STATUS_SUCCESS,
        ]);
    }

    public function test_successful_login_is_allowed_within_the_ip_limit(): void
    {
        $ip = '198.51.100.30';
        $user = User::factory()->create([
            'email' => 'test@example.com',
            'password' => Hash::make('Password1'),
        ]);

        foreach (range(1, 19) as $attempt) {
            $this->withServerVariables(['REMOTE_ADDR' => $ip])
                ->postJson('/api/login', [
                    'email' => "unknown{$attempt}@example.com",
                    'password' => 'WrongPassword1',
                ])
                ->assertUnprocessable();
        }

        $this->withServerVariables(['REMOTE_ADDR' => $ip])
            ->postJson('/api/login', [
                'email' => $user->email,
                'password' => 'Password1',
            ])
            ->assertOk()
            ->assertJsonPath('message', 'Login successful.');

        $this->assertDatabaseCount('auth_audit_logs', 20);
        $this->assertDatabaseCount('personal_access_tokens', 1);
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
