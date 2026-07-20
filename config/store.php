<?php

return [

    /*
    |--------------------------------------------------------------------------
    | SMS sender driver
    |--------------------------------------------------------------------------
    |
    | Which implementation of App\Services\Sms\SmsSender to use when delivering
    | one-time passwords. "log" writes the message to the application log, which
    | keeps local development and the test suite free of external dependencies.
    | Swap this for a real gateway (Twilio, Vonage, ...) by binding another
    | implementation in App\Providers\AppServiceProvider.
    |
    */
    'sms_sender' => env('SMS_SENDER', 'log'),

    /*
    |--------------------------------------------------------------------------
    | One-time password settings
    |--------------------------------------------------------------------------
    */
    'otp' => [
        'ttl_minutes' => (int) env('OTP_TTL_MINUTES', 10),
        'length' => (int) env('OTP_LENGTH', 6),
        // Max issue requests allowed per phone number within the TTL window.
        'max_per_window' => (int) env('OTP_MAX_PER_WINDOW', 3),
    ],

    /*
    |--------------------------------------------------------------------------
    | Seeded admin account
    |--------------------------------------------------------------------------
    */
    'admin' => [
        'phone' => env('ADMIN_SEED_PHONE', '+10000000001'),
        'password' => env('ADMIN_SEED_PASSWORD', 'password'),
    ],

];
