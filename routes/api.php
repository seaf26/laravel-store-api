<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\OrderController;
use App\Http\Controllers\Api\PasswordResetController;
use App\Http\Controllers\Api\PhoneVerificationController;
use App\Http\Controllers\Api\ProductController;
use App\Http\Controllers\Api\StockSubscriptionController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Authentication
|--------------------------------------------------------------------------
*/
Route::prefix('auth')->group(function () {
    Route::post('register', [AuthController::class, 'register'])
        ->middleware('throttle:phone-verification-issue');
    Route::post('login', [AuthController::class, 'login'])
        ->middleware('throttle:login');
    Route::get('me', [AuthController::class, 'me'])->middleware('auth:sanctum');
    Route::post('logout', [AuthController::class, 'logout'])->middleware('auth:sanctum');
    Route::post('logout-all', [AuthController::class, 'logoutAll'])->middleware('auth:sanctum');

    // Phone verification
    Route::post('verify-phone/request', [PhoneVerificationController::class, 'request'])
        ->middleware('throttle:phone-verification-issue');
    Route::post('verify-phone', [PhoneVerificationController::class, 'verify'])
        ->middleware('throttle:phone-verification-attempt');

    // Password reset
    Route::post('password/forgot', [PasswordResetController::class, 'forgot'])
        ->middleware('throttle:password-reset-issue');
    Route::post('password/reset', [PasswordResetController::class, 'reset'])
        ->middleware('throttle:password-reset-attempt');
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

    // Ask to be notified when an out-of-stock product is available again.
    Route::post('products/{product}/notify-me', StockSubscriptionController::class);

    // The user's own notification feed.
    Route::get('notifications', [NotificationController::class, 'index']);
    Route::patch('notifications/{id}/read', [NotificationController::class, 'markAsRead']);

    // Orders — a user sees only their own; an admin sees all.
    Route::get('orders', [OrderController::class, 'index']);
    Route::get('orders/{order}', [OrderController::class, 'show']);
    Route::post('orders', [OrderController::class, 'store']);
    // Admin-only status change (enforced by OrderPolicy@updateStatus).
    Route::patch('orders/{order}/status', [OrderController::class, 'updateStatus']);
});
