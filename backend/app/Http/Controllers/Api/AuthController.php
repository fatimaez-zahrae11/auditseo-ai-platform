<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\LoginRequest;
use App\Http\Requests\RegisterRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

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

        $token = $this->createToken($user);

        return response()->json([
            'message' => 'Registration successful.',
            'user' => $user,
            'token' => $token,
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
            return response()->json([
                'message' => 'Invalid credentials.',
            ], 422);
        }

        $user->tokens()->delete();

        $token = $this->createToken($user);

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
        $request->user()->currentAccessToken()?->delete();

        return response()->json([
            'message' => 'Logout successful.',
        ]);
    }

    public function logoutAll(Request $request): JsonResponse
    {
        $request->user()->tokens()->delete();

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
}
