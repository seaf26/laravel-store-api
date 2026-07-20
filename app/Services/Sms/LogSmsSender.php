<?php

namespace App\Services\Sms;

use Illuminate\Support\Facades\Log;

/**
 * Development/testing SMS driver: writes the message to the application log
 * instead of contacting an external gateway.
 */
class LogSmsSender implements SmsSender
{
    public function send(string $phone, string $message): void
    {
        Log::info('SMS dispatched', [
            'phone' => $phone,
            'message' => $message,
        ]);
    }
}
