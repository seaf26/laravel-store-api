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
    | "twilio" sends a real SMS via the Twilio REST API (see the twilio config
    | block below). Add another provider the same way: implement SmsSender and
    | register it in the match statement in App\Providers\AppServiceProvider.
    |
    */
    'sms_sender' => env('SMS_SENDER', 'log'),

    /*
    |--------------------------------------------------------------------------
    | Twilio
    |--------------------------------------------------------------------------
    |
    | Only used when sms_sender=twilio. Get a trial SID/token from
    | https://www.twilio.com/try-twilio - trial accounts can only send to
    | phone numbers you've verified in the Twilio console.
    |
    */
    'twilio' => [
        'sid' => env('TWILIO_SID'),
        'token' => env('TWILIO_AUTH_TOKEN'),
        'from' => env('TWILIO_FROM_NUMBER'),
    ],

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
