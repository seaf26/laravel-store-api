<?php

namespace App\Services\Sms;

/**
 * Gateway seam for outbound SMS.
 *
 * The application only ever depends on this contract, so swapping the log
 * driver for a real provider (Twilio, Vonage, ...) is a container binding
 * change in AppServiceProvider and needs no changes to calling code.
 */
interface SmsSender
{
    public function send(string $phone, string $message): void;
}
