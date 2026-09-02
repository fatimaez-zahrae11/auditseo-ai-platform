<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ActionLog;
use App\Models\AuthAuditLog;
use App\Models\User;
use App\Services\ActionLogger;
use App\Support\EmailAddress;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;
use Throwable;

class GoogleOAuthController extends Controller
{
    private const STATE_TTL_MINUTES = 5;

    private const EXCHANGE_CODE_TTL_MINUTES = 2;

    private const INVALID_OAUTH_MESSAGE = 'Google sign-in could not be completed. Please try again.';

    private const INVALID_EXCHANGE_MESSAGE = 'The Google sign-in code is invalid or has expired.';

    private const DISABLED_ACCOUNT_MESSAGE = 'This account is disabled. Please contact an administrator.';

    public function __construct(private readonly ActionLogger $actionLogger) {}

    public function redirect(Request $request): JsonResponse
    {
        if (! $this->hasGoogleConfiguration()) {
            return response()->json([
                'message' => 'Google sign-in is temporarily unavailable.',
            ], 503);
        }

        $state = Str::random(64);
        try {
            Cache::put($this->stateCacheKey($state), true, now()->addMinutes(self::STATE_TTL_MINUTES));
            $url = Socialite::driver('google')
                ->stateless()
                ->with(['state' => $state, 'prompt' => 'select_account'])
                ->redirect()
                ->getTargetUrl();
        } catch (Throwable) {
            $this->recordAuthEvent($request, AuthAuditLog::EVENT_GOOGLE_OAUTH_REDIRECT, AuthAuditLog::STATUS_FAILED);

            return response()->json([
                'message' => 'Google sign-in is temporarily unavailable.',
            ], 503);
        }

        $this->recordAuthEvent($request, AuthAuditLog::EVENT_GOOGLE_OAUTH_REDIRECT, AuthAuditLog::STATUS_SUCCESS);
        $this->actionLogger->log(null, ActionLog::ACTION_GOOGLE_OAUTH_REDIRECT_REQUESTED);

        return response()->json(['url' => $url]);
    }

    public function callback(Request $request): JsonResponse|RedirectResponse
    {
        if (! $this->hasGoogleConfiguration() || $this->frontendCallbackUrl() === null) {
            return response()->json([
                'message' => 'Google sign-in is temporarily unavailable.',
            ], 503);
        }

        $state = $request->query('state');
        try {
            $validState = is_string($state)
                && strlen($state) === 64
                && Cache::pull($this->stateCacheKey($state)) === true;
        } catch (Throwable) {
            return response()->json([
                'message' => 'Google sign-in is temporarily unavailable.',
            ], 503);
        }

        if (! $validState) {
            $this->recordAuthEvent($request, AuthAuditLog::EVENT_GOOGLE_OAUTH_LOGIN, AuthAuditLog::STATUS_FAILED);

            return response()->json(['message' => self::INVALID_OAUTH_MESSAGE], 422);
        }

        if ($request->filled('error') || ! $request->filled('code')) {
            $this->recordAuthEvent($request, AuthAuditLog::EVENT_GOOGLE_OAUTH_LOGIN, AuthAuditLog::STATUS_FAILED);

            return response()->json(['message' => self::INVALID_OAUTH_MESSAGE], 422);
        }

        try {
            $googleUser = Socialite::driver('google')->stateless()->user();
            $identity = $this->validatedIdentity($googleUser);
            if ($identity === null) {
                $this->recordAuthEvent($request, AuthAuditLog::EVENT_GOOGLE_OAUTH_LOGIN, AuthAuditLog::STATUS_FAILED);

                return response()->json(['message' => self::INVALID_OAUTH_MESSAGE], 422);
            }

            [$user, $linked] = $this->resolveUser($identity);
        } catch (Throwable) {
            $this->recordAuthEvent($request, AuthAuditLog::EVENT_GOOGLE_OAUTH_LOGIN, AuthAuditLog::STATUS_FAILED);

            return response()->json(['message' => self::INVALID_OAUTH_MESSAGE], 422);
        }

        if (! $user->is_active) {
            $this->recordAuthEvent(
                $request,
                AuthAuditLog::EVENT_GOOGLE_OAUTH_LOGIN,
                AuthAuditLog::STATUS_FAILED,
                $user,
                $user->email,
            );

            return response()->json(['message' => self::DISABLED_ACCOUNT_MESSAGE], 403);
        }

        if ($linked) {
            $this->actionLogger->log($user, ActionLog::ACTION_GOOGLE_OAUTH_ACCOUNT_LINKED, $user);
        }

        $oneTimeCode = Str::random(64);
        try {
            Cache::put($this->exchangeCacheKey($oneTimeCode), [
                'user_id' => $user->getKey(),
            ], now()->addMinutes(self::EXCHANGE_CODE_TTL_MINUTES));
        } catch (Throwable) {
            return response()->json([
                'message' => 'Google sign-in is temporarily unavailable.',
            ], 503);
        }

        return redirect()->away($this->frontendCallbackUrl().'?'.http_build_query([
            'code' => $oneTimeCode,
        ], '', '&', PHP_QUERY_RFC3986));
    }

    public function exchange(Request $request): JsonResponse
    {
        $code = $request->input('code');
        if (! is_string($code) || strlen($code) !== 64) {
            return response()->json(['message' => self::INVALID_EXCHANGE_MESSAGE], 422);
        }

        try {
            $payload = Cache::pull($this->exchangeCacheKey($code));
        } catch (Throwable) {
            return response()->json([
                'message' => 'Google sign-in is temporarily unavailable.',
            ], 503);
        }
        if (! is_array($payload) || ! isset($payload['user_id'])) {
            return response()->json(['message' => self::INVALID_EXCHANGE_MESSAGE], 422);
        }

        $user = User::query()->find($payload['user_id']);
        if (! $user) {
            return response()->json(['message' => self::INVALID_EXCHANGE_MESSAGE], 422);
        }

        if (! $user->is_active) {
            $this->recordAuthEvent(
                $request,
                AuthAuditLog::EVENT_GOOGLE_OAUTH_LOGIN,
                AuthAuditLog::STATUS_FAILED,
                $user,
                $user->email,
            );

            return response()->json(['message' => self::DISABLED_ACCOUNT_MESSAGE], 403);
        }

        $user->tokens()->delete();
        $expirationMinutes = (int) config('sanctum.expiration', 1440);
        $token = $user->createToken('api-token', ['*'], now()->addMinutes($expirationMinutes))->plainTextToken;

        $this->recordAuthEvent(
            $request,
            AuthAuditLog::EVENT_GOOGLE_OAUTH_LOGIN,
            AuthAuditLog::STATUS_SUCCESS,
            $user,
            $user->email,
        );
        $this->actionLogger->log($user, ActionLog::ACTION_GOOGLE_OAUTH_LOGIN, $user);

        return response()->json([
            'message' => 'Google sign-in successful.',
            'user' => $user,
            'token' => $token,
        ]);
    }

    /**
     * @return array{id: string, email: string, name: string}|null
     */
    private function validatedIdentity(SocialiteUser $googleUser): ?array
    {
        $raw = $googleUser->getRaw();
        $emailVerified = filter_var(
            $raw['email_verified'] ?? $raw['verified_email'] ?? false,
            FILTER_VALIDATE_BOOL,
        );
        $googleId = trim((string) $googleUser->getId());
        $email = EmailAddress::canonicalize((string) $googleUser->getEmail());

        if (! $emailVerified
            || $googleId === ''
            || strlen($googleId) > 255
            || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return null;
        }

        $name = trim((string) $googleUser->getName());
        if ($name === '') {
            $name = Str::before($email, '@');
        }

        return [
            'id' => $googleId,
            'email' => $email,
            'name' => Str::limit($name, 255, ''),
        ];
    }

    /**
     * @param  array{id: string, email: string, name: string}  $identity
     * @return array{0: User, 1: bool}
     */
    private function resolveUser(array $identity): array
    {
        return DB::transaction(function () use ($identity): array {
            $user = User::query()->where('google_id', $identity['id'])->lockForUpdate()->first();
            if ($user) {
                return [$user, false];
            }

            $user = User::query()->where('email', $identity['email'])->lockForUpdate()->first();
            if ($user) {
                if (! $user->is_active) {
                    return [$user, false];
                }

                if ($user->google_id !== null && $user->google_id !== $identity['id']) {
                    throw new \RuntimeException('Google identity conflict.');
                }

                $user->forceFill([
                    'google_id' => $identity['id'],
                    'email_verified_at' => $user->email_verified_at ?? now(),
                ])->save();

                return [$user, true];
            }

            $user = new User;
            $user->forceFill([
                'name' => $identity['name'],
                'email' => $identity['email'],
                'google_id' => $identity['id'],
                'email_verified_at' => now(),
                'password' => Hash::make(Str::random(64)),
                'role' => User::ROLE_USER,
                'is_active' => true,
            ])->save();

            return [$user, false];
        });
    }

    private function hasGoogleConfiguration(): bool
    {
        foreach (['client_id', 'client_secret', 'redirect'] as $key) {
            if (trim((string) config("services.google.{$key}")) === '') {
                return false;
            }
        }

        return $this->isHttpUrl((string) config('services.google.redirect'));
    }

    private function frontendCallbackUrl(): ?string
    {
        $frontendUrl = rtrim((string) config('services.frontend.url'), '/');

        return ! $this->isHttpUrl($frontendUrl)
            ? null
            : $frontendUrl.'/auth/google/callback';
    }

    private function isHttpUrl(string $url): bool
    {
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));

        return in_array($scheme, ['http', 'https'], true)
            && filter_var($url, FILTER_VALIDATE_URL) !== false;
    }

    private function stateCacheKey(string $state): string
    {
        return 'oauth:google:state:'.hash('sha256', $state);
    }

    private function exchangeCacheKey(string $code): string
    {
        return 'oauth:google:exchange:'.hash('sha256', $code);
    }

    private function recordAuthEvent(
        Request $request,
        string $event,
        string $status,
        ?User $user = null,
        ?string $email = null,
    ): void {
        AuthAuditLog::query()->create([
            'user_id' => $user?->getKey(),
            'email' => $email === null
                ? null
                : Str::limit(EmailAddress::canonicalize($email), 255, ''),
            'event' => $event,
            'ip_address' => Str::limit((string) $request->ip(), 45, ''),
            'user_agent' => $request->userAgent() === null
                ? null
                : Str::limit($request->userAgent(), 1000, ''),
            'status' => $status,
        ]);
    }
}
