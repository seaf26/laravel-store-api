<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\PasswordResetController;
use App\Http\Controllers\Api\PhoneVerificationController;
use App\Http\Controllers\Api\ProductController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Authentication
|--------------------------------------------------------------------------
*/
Route::prefix('auth')->group(function () {
    Route::post('register', [AuthController::class, 'register']);
    Route::post('login', [AuthController::class, 'login']);
    Route::post('logout', [AuthController::class, 'logout'])->middleware('auth:sanctum');

    // Phone verification
    Route::post('verify-phone/request', [PhoneVerificationController::class, 'request']);
    Route::post('verify-phone', [PhoneVerificationController::class, 'verify']);

    // Password reset
    Route::post('password/forgot', [PasswordResetController::class, 'forgot']);
    Route::post('password/reset', [PasswordResetController::class, 'reset']);
});

/*
|--------------------------------------------------------------------------
| Authenticated API
|--------------------------------------------------------------------------
*/
Route::middleware('auth:sanctum')->group(function () {
    // Browsing is open to any authenticated user; writes are admin-only
    // (enforced by ProductPolicy).
    Route::apiResource('products', ProductController::class);
});
