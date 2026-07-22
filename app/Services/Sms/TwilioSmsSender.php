<?php

namespace App\Services\Sms;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Sends OTP messages via the Twilio REST API.
 *
 * Uses the Http facade directly against Twilio's Messages endpoint rather
 * than pulling in the full twilio/sdk package for a single POST call.
 */
class TwilioSmsSender implements SmsSender
{
    public function __construct(
        private readonly string $sid,
        private readonly string $token,
        private readonly string $from,
    ) {
        if ($this->sid === '' || $this->token === '' || $this->from === '') {
            throw new RuntimeException(
                'SMS_SENDER=twilio requires TWILIO_SID, TWILIO_AUTH_TOKEN, and TWILIO_FROM_NUMBER to be set.'
            );
        }
    }

    public function send(string $phone, string $message): void
    {
        $response = Http::asForm()
            ->withBasicAuth($this->sid, $this->token)
            ->post("https://api.twilio.com/2010-04-01/Accounts/{$this->sid}/Messages.json", [
                'To' => $phone,
                'From' => $this->from,
                'Body' => $message,
            ]);

        if ($response->failed()) {
            throw new RuntimeException(
                'Twilio SMS delivery failed ('.$response->status().'): '.$response->body()
            );
        }
    }
}
