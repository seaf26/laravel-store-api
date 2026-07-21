<?php

namespace App\Http\Controllers\Api;

use App\Enums\OtpPurpose;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\RegisterRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\Otp\OtpService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function __construct(private readonly OtpService $otp) {}

    /**
     * Register a new account using a phone number.
     */
    public function register(RegisterRequest $request): JsonResponse
    {
        $user = User::create($request->validated());

        $this->otp->issue($user->phone, OtpPurpose::PhoneVerification);

        return response()->json([
            'message' => 'Account created. A verification code has been sent to your phone.',
            'user' => new UserResource($user),
        ], 201);
    }

    /**
     * Exchange phone + password for a Sanctum API token.
     */
    public function login(LoginRequest $request): JsonResponse
    {
        $user = User::where('phone', $request->string('phone'))->first();

        if (! $user || ! Hash::check($request->string('password'), $user->password)) {
            // Generic message so we never reveal which field was wrong.
            throw ValidationException::withMessages([
                'phone' => ['The provided credentials are incorrect.'],
            ]);
        }

        if (! $user->hasVerifiedPhone()) {
            return response()->json([
                'message' => 'Phone number is not verified.',
            ], 403);
        }

        $token = $user->createToken('api')->plainTextToken;

        return response()->json([
            'token' => $token,
            'user' => new UserResource($user),
        ]);
    }

    /**
     * Revoke the token used to authenticate the current request.
     */
    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json([
            'message' => 'Logged out.',
        ]);
    }
}
