<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ActionLog;
use App\Models\User;
use App\Services\ActionLogger;
use App\Support\EmailAddress;
use Illuminate\Auth\Events\Verified;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EmailVerificationController extends Controller
{
    private const RESEND_MESSAGE = 'If the email is registered and unverified, a verification link has been sent.';

    public function __construct(private readonly ActionLogger $actionLogger) {}

    public function verify(Request $request, int $id, string $hash): JsonResponse
    {
        if (! $request->hasValidSignature()) {
            return response()->json([
                'message' => 'The verification link is invalid or has expired.',
            ], 403);
        }

        $user = User::findOrFail($id);

        if (! hash_equals(sha1($user->getEmailForVerification()), $hash)) {
            return response()->json([
                'message' => 'The verification link is invalid or has expired.',
            ], 403);
        }

        $wasUnverified = ! $user->hasVerifiedEmail();

        if ($wasUnverified && $user->markEmailAsVerified()) {
            event(new Verified($user));
            $this->actionLogger->log(
                $user,
                ActionLog::ACTION_EMAIL_VERIFIED,
                $user,
            );
        }

        return response()->json([
            'message' => 'Email verified successfully. You may now log in.',
        ]);
    }

    public function resend(Request $request): JsonResponse
    {
        $email = $request->input('email');

        if (is_string($email)) {
            $request->merge([
                'email' => EmailAddress::canonicalize($email),
            ]);
        }

        $validated = $request->validate([
            'email' => ['required', 'email', 'max:255'],
        ]);
        $user = User::where('email', $validated['email'])->first();

        if ($user && ! $user->hasVerifiedEmail()) {
            $user->sendEmailVerificationNotification();
            $this->actionLogger->log(
                $user,
                ActionLog::ACTION_EMAIL_VERIFICATION_REQUESTED,
                $user,
            );
        }

        return response()->json([
            'message' => self::RESEND_MESSAGE,
        ]);
    }
}
