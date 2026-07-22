<?php

namespace App\Notifications\Channels;

use App\Services\Sms\SmsSender;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Delivers a notification through the configured SmsSender (log/Twilio/etc).
 *
 * A notification opts in by defining toSms(): ?string. Returning null (or
 * omitting the method) means "don't text this one" without having to branch
 * inside via().
 */
class SmsChannel
{
    public function __construct(private readonly SmsSender $sms) {}

    public function send(object $notifiable, Notification $notification): void
    {
        if (! method_exists($notification, 'toSms')) {
            return;
        }

        $phone = $notifiable->phone ?? null;

        if (! $phone) {
            return;
        }

        $message = $notification->toSms($notifiable);

        if ($message === null) {
            return;
        }

        // Best-effort: the database notification (or whatever other channel
        // ran) has already succeeded by the time this runs, so a gateway
        // failure here must not fail the whole queued job or retry storm the
        // customer with duplicate texts.
        try {
            $this->sms->send($phone, $message);
        } catch (Throwable $e) {
            Log::warning('SMS notification delivery failed', [
                'phone' => $phone,
                'notification' => $notification::class,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
