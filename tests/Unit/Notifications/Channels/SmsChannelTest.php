<?php

namespace Tests\Unit\Notifications\Channels;

use App\Notifications\Channels\SmsChannel;
use App\Services\Sms\SmsSender;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class SmsChannelTest extends TestCase
{
    public function test_it_sends_the_message_returned_by_to_sms(): void
    {
        $sms = $this->createMock(SmsSender::class);
        $sms->expects($this->once())->method('send')->with('+201000000001', 'hello');

        $notifiable = (object) ['phone' => '+201000000001'];
        $notification = new class extends Notification
        {
            public function toSms(object $notifiable): ?string
            {
                return 'hello';
            }
        };

        (new SmsChannel($sms))->send($notifiable, $notification);
    }

    public function test_it_does_nothing_when_to_sms_returns_null(): void
    {
        $sms = $this->createMock(SmsSender::class);
        $sms->expects($this->never())->method('send');

        $notifiable = (object) ['phone' => '+201000000001'];
        $notification = new class extends Notification
        {
            public function toSms(object $notifiable): ?string
            {
                return null;
            }
        };

        (new SmsChannel($sms))->send($notifiable, $notification);
    }

    public function test_it_does_nothing_when_the_notification_has_no_to_sms_method(): void
    {
        $sms = $this->createMock(SmsSender::class);
        $sms->expects($this->never())->method('send');

        $notifiable = (object) ['phone' => '+201000000001'];
        $notification = new class extends Notification {};

        (new SmsChannel($sms))->send($notifiable, $notification);
    }

    public function test_it_does_nothing_when_the_notifiable_has_no_phone(): void
    {
        $sms = $this->createMock(SmsSender::class);
        $sms->expects($this->never())->method('send');

        $notifiable = (object) ['phone' => null];
        $notification = new class extends Notification
        {
            public function toSms(object $notifiable): ?string
            {
                return 'hello';
            }
        };

        (new SmsChannel($sms))->send($notifiable, $notification);
    }

    public function test_a_delivery_failure_is_logged_and_does_not_throw(): void
    {
        $sms = $this->createMock(SmsSender::class);
        $sms->method('send')->willThrowException(new \RuntimeException('gateway down'));

        Log::shouldReceive('warning')->once()->with(
            'SMS notification delivery failed',
            $this->callback(fn ($context) => $context['phone'] === '+201000000001'),
        );

        $notifiable = (object) ['phone' => '+201000000001'];
        $notification = new class extends Notification
        {
            public function toSms(object $notifiable): ?string
            {
                return 'hello';
            }
        };

        // Must not throw - a gateway failure is best-effort, not fatal.
        (new SmsChannel($sms))->send($notifiable, $notification);
        $this->assertTrue(true);
    }
}
