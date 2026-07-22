<?php

namespace Tests\Unit\Services\Sms;

use App\Services\Sms\TwilioSmsSender;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class TwilioSmsSenderTest extends TestCase
{
    public function test_it_posts_to_the_twilio_messages_endpoint_with_basic_auth(): void
    {
        Http::fake(['api.twilio.com/*' => Http::response(['sid' => 'SM123'], 201)]);

        (new TwilioSmsSender(sid: 'ACxxx', token: 'secret-token', from: '+15005550006'))
            ->send('+201000000001', 'Your code is 123456.');

        Http::assertSent(function ($request) {
            return $request->url() === 'https://api.twilio.com/2010-04-01/Accounts/ACxxx/Messages.json'
                && $request['To'] === '+201000000001'
                && $request['From'] === '+15005550006'
                && $request['Body'] === 'Your code is 123456.'
                && $request->hasHeader('Authorization');
        });
    }

    public function test_a_failed_twilio_response_throws(): void
    {
        Http::fake(['api.twilio.com/*' => Http::response(['message' => 'The From number is not valid'], 400)]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/Twilio SMS delivery failed \(400\)/');

        (new TwilioSmsSender(sid: 'ACxxx', token: 'secret-token', from: 'not-a-number'))
            ->send('+201000000001', 'Your code is 123456.');
    }

    public function test_missing_credentials_fail_fast_at_construction(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/TWILIO_SID, TWILIO_AUTH_TOKEN, and TWILIO_FROM_NUMBER/');

        new TwilioSmsSender(sid: '', token: 'secret-token', from: '+15005550006');
    }

    public function test_no_real_request_ever_leaves_the_test_process(): void
    {
        Http::preventStrayRequests();
        Http::fake(['api.twilio.com/*' => Http::response(['sid' => 'SM123'], 201)]);

        (new TwilioSmsSender(sid: 'ACxxx', token: 'secret-token', from: '+15005550006'))
            ->send('+201000000001', 'test');

        Http::assertSentCount(1);
    }
}
