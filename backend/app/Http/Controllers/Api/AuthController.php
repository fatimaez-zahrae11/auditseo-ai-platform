<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\LoginRequest;
use App\Http\Requests\RegisterRequest;
use App\Models\AuthAuditLog;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class AuthController extends Controller
{
    private const DUMMY_PASSWORD_HASH = '$2y$12$wD95k2pP2KnUv2eRsga.O.dOYzIlM/f4JG/07Ts8GBxW0bIhWbMhO';

    public function register(RegisterRequest $request): JsonResponse
    {
        $user = User::create([
            'name' => $request->validated('name'),
            'email' => $request->validated('email'),
            'password' => Hash::make($request->validated('password')),
        ]);

        $user->sendEmailVerificationNotification();

        return response()->json([
            'message' => 'Registration successful. Please verify your email before logging in.',
        ], 201);
    }

    public function login(LoginRequest $request): JsonResponse
    {
        $user = User::where('email', $request->validated('email'))->first();
        $passwordMatches = Hash::check(
            $request->validated('password'),
            $user?->password ?? self::DUMMY_PASSWORD_HASH,
        );

        if (! $user || ! $passwordMatches) {
            $this->recordAuthEvent(
                request: $request,
                event: AuthAuditLog::EVENT_LOGIN,
                status: AuthAuditLog::STATUS_FAILED,
                user: $user,
                email: $request->validated('email'),
            );

            return response()->json([
                'message' => 'Invalid credentials.',
            ], 422);
        }

        if (! $user->hasVerifiedEmail()) {
            $this->recordAuthEvent(
                request: $request,
                event: AuthAuditLog::EVENT_LOGIN,
                status: AuthAuditLog::STATUS_FAILED,
                user: $user,
                email: $user->email,
            );

            return response()->json([
                'message' => 'Email verification is required before login.',
            ], 403);
        }

        $user->tokens()->delete();

        $token = $this->createToken($user);

        $this->recordAuthEvent(
            request: $request,
            event: AuthAuditLog::EVENT_LOGIN,
            status: AuthAuditLog::STATUS_SUCCESS,
            user: $user,
            email: $user->email,
        );

        return response()->json([
            'message' => 'Login successful.',
            'user' => $user,
            'token' => $token,
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json([
            'user' => $request->user(),
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $user = $request->user();

        $user->currentAccessToken()?->delete();

        $this->recordAuthEvent(
            request: $request,
            event: AuthAuditLog::EVENT_LOGOUT,
            status: AuthAuditLog::STATUS_SUCCESS,
            user: $user,
            email: $user->email,
        );

        return response()->json([
            'message' => 'Logout successful.',
        ]);
    }

    public function logoutAll(Request $request): JsonResponse
    {
        $user = $request->user();

        $user->tokens()->delete();

        $this->recordAuthEvent(
            request: $request,
            event: AuthAuditLog::EVENT_LOGOUT_ALL,
            status: AuthAuditLog::STATUS_SUCCESS,
            user: $user,
            email: $user->email,
        );

        return response()->json([
            'message' => 'All sessions logged out successfully.',
        ]);
    }

    private function createToken(User $user): string
    {
        $expirationMinutes = (int) config('sanctum.expiration', 1440);
        $expiresAt = now()->addMinutes($expirationMinutes);

        return $user->createToken('api-token', ['*'], $expiresAt)->plainTextToken;
    }

    private function recordAuthEvent(
        Request $request,
        string $event,
        string $status,
        ?User $user,
        ?string $email,
    ): void {
        AuthAuditLog::create([
            'user_id' => $user?->id,
            'email' => $email === null ? null : Str::limit(Str::lower(trim($email)), 255, ''),
            'event' => $event,
            'ip_address' => Str::limit((string) $request->ip(), 45, ''),
            'user_agent' => $request->userAgent() === null
                ? null
                : Str::limit($request->userAgent(), 1000, ''),
            'status' => $status,
        ]);
    }
}
