<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\EmailAddress;
use Illuminate\Auth\Events\PasswordReset as PasswordResetEvent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Throwable;

class PasswordResetController extends Controller
{
    private const FORGOT_PASSWORD_MESSAGE = 'If an account exists for this email, a password reset link has been sent.';

    private const INVALID_RESET_LINK_MESSAGE = 'This password reset link is invalid or has expired.';

    private const PASSWORD_RESET_MESSAGE = 'Your password has been reset successfully. You may now sign in.';

    public function forgotPassword(Request $request): JsonResponse
    {
        $this->canonicalizeEmail($request);

        $validated = $request->validate([
            'email' => ['required', 'email', 'max:255'],
        ]);

        try {
            Password::broker()->sendResetLink([
                'email' => $validated['email'],
            ]);
        } catch (Throwable) {
            // The public response intentionally does not disclose account or mail-provider state.
        }

        return response()->json([
            'message' => self::FORGOT_PASSWORD_MESSAGE,
        ]);
    }

    public function resetPassword(Request $request): JsonResponse
    {
        $this->canonicalizeEmail($request);

        $validated = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'token' => ['required', 'string'],
            'password' => [
                'required',
                'string',
                'confirmed',
                'min:8',
                'regex:/[A-Z]/',
                'regex:/[0-9]/',
            ],
        ], [
            'password.regex' => 'The password must contain at least one uppercase letter and one number.',
        ]);

        try {
            $status = Password::broker()->reset(
                $validated,
                function (User $user, string $password): void {
                    $user->forceFill([
                        'password' => Hash::make($password),
                        'remember_token' => Str::random(60),
                    ])->save();

                    $user->tokens()->delete();

                    event(new PasswordResetEvent($user));
                },
            );
        } catch (Throwable) {
            $status = null;
        }

        if ($status !== Password::PASSWORD_RESET) {
            return response()->json([
                'message' => self::INVALID_RESET_LINK_MESSAGE,
            ], 422);
        }

        return response()->json([
            'message' => self::PASSWORD_RESET_MESSAGE,
        ]);
    }

    private function canonicalizeEmail(Request $request): void
    {
        $email = $request->input('email');

        if (is_string($email)) {
            $request->merge([
                'email' => EmailAddress::canonicalize($email),
            ]);
        }
    }
}
