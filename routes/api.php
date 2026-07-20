<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\PasswordResetController;
use App\Http\Controllers\Api\PhoneVerificationController;
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
