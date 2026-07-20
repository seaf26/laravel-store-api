<?php

namespace App\Http\Controllers\Api;

use App\Enums\OtpPurpose;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\RequestVerificationCodeRequest;
use App\Http\Requests\Auth\VerifyPhoneRequest;
use App\Models\User;
use App\Services\Otp\OtpService;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;

class PhoneVerificationController extends Controller
{
    public function __construct(private readonly OtpService $otp) {}

    /**
     * Send (or resend) a phone verification code.
     */
    public function request(RequestVerificationCodeRequest $request): JsonResponse
    {
        $phone = (string) $request->string('phone');
        $user = User::where('phone', $phone)->first();

        // A code is only really sent to an existing, unverified account, but the
        // response is identical either way so the endpoint cannot be used to
        // discover which phone numbers are registered.
        if ($user && ! $user->hasVerifiedPhone()) {
            $this->otp->issue($phone, OtpPurpose::PhoneVerification);
        }

        return response()->json([
            'message' => 'If the phone number requires verification, a code has been sent.',
        ]);
    }

    /**
     * Confirm ownership of the phone number using the code.
     */
    public function verify(VerifyPhoneRequest $request): JsonResponse
    {
        $phone = (string) $request->string('phone');
        $user = User::where('phone', $phone)->first();

        if (! $user || ! $this->otp->verify($phone, OtpPurpose::PhoneVerification, (string) $request->string('code'))) {
            throw ValidationException::withMessages([
                'code' => ['Invalid or expired code.'],
            ]);
        }

        if (! $user->hasVerifiedPhone()) {
            $user->forceFill(['phone_verified_at' => now()])->save();
        }

        return response()->json([
            'message' => 'Phone number verified.',
        ]);
    }
}
