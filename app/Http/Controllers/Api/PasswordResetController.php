<?php

namespace App\Http\Controllers\Api;

use App\Enums\OtpPurpose;
use App\Exceptions\TooManyOtpRequestsException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ForgotPasswordRequest;
use App\Http\Requests\Auth\ResetPasswordRequest;
use App\Models\User;
use App\Services\Otp\OtpService;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;

class PasswordResetController extends Controller
{
    public function __construct(private readonly OtpService $otp) {}

    /**
     * Send a password reset code to a registered phone number.
     */
    public function forgot(ForgotPasswordRequest $request): JsonResponse
    {
        $phone = (string) $request->string('phone');

        if (User::where('phone', $phone)->exists()) {
            try {
                $this->otp->issue($phone, OtpPurpose::PasswordReset);
            } catch (TooManyOtpRequestsException) {
                // Keep the public response indistinguishable when the
                // database/lock guard rejects delivery independently of the
                // named middleware buckets.
            }
        }

        // Identical response either way, so the endpoint cannot be used to
        // enumerate registered phone numbers.
        return response()->json([
            'message' => 'If the phone number is registered, a reset code has been sent.',
        ]);
    }

    /**
     * Set a new password using a valid reset code.
     */
    public function reset(ResetPasswordRequest $request): JsonResponse
    {
        $phone = (string) $request->string('phone');
        $user = User::where('phone', $phone)->first();

        if (! $user || ! $this->otp->verify($phone, OtpPurpose::PasswordReset, (string) $request->string('code'))) {
            throw ValidationException::withMessages([
                'code' => ['Invalid or expired code.'],
            ]);
        }

        $user->forceFill(['password' => $request->string('password')])->save();

        // Every existing session is revoked: a password reset must lock out
        // anyone holding a previously issued token.
        $user->tokens()->delete();

        return response()->json([
            'message' => 'Password has been reset.',
        ]);
    }
}
