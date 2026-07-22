<?php

namespace Tests\Feature\Auth;

use App\Enums\OtpPurpose;
use App\Models\OtpCode;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_user_can_reset_their_password_with_a_code(): void
    {
        $sms = $this->fakeSms();
        User::factory()->create(['phone' => '+201234567890', 'password' => 'old-password']);

        $this->postJson('/api/auth/password/forgot', ['phone' => '+201234567890'])->assertOk();

        $this->postJson('/api/auth/password/reset', [
            'phone' => '+201234567890',
            'code' => $sms->latestCodeFor('+201234567890'),
            'password' => 'new-password123',
            'password_confirmation' => 'new-password123',
        ])->assertOk();

        $this->assertTrue(Hash::check('new-password123', User::first()->password));

        // The new password works, the old one does not.
        $this->postJson('/api/auth/login', [
            'phone' => '+201234567890',
            'password' => 'new-password123',
        ])->assertOk();

        $this->postJson('/api/auth/login', [
            'phone' => '+201234567890',
            'password' => 'old-password',
        ])->assertStatus(422);
    }

    public function test_resetting_the_password_revokes_all_existing_tokens(): void
    {
        $sms = $this->fakeSms();
        $user = User::factory()->create(['phone' => '+201234567890', 'password' => 'old-password']);

        $token = $this->postJson('/api/auth/login', [
            'phone' => '+201234567890',
            'password' => 'old-password',
        ])->json('token');

        $this->postJson('/api/auth/password/forgot', ['phone' => '+201234567890'])->assertOk();
        $this->postJson('/api/auth/password/reset', [
            'phone' => '+201234567890',
            'code' => $sms->latestCodeFor('+201234567890'),
            'password' => 'new-password123',
            'password_confirmation' => 'new-password123',
        ])->assertOk();

        $this->assertSame(0, $user->fresh()->tokens()->count());

        $this->app['auth']->forgetGuards();
        $this->withToken($token)->postJson('/api/auth/logout')->assertStatus(401);
    }

    public function test_an_invalid_code_is_rejected(): void
    {
        $this->fakeSms();
        User::factory()->create(['phone' => '+201234567890', 'password' => 'old-password']);
        $this->postJson('/api/auth/password/forgot', ['phone' => '+201234567890'])->assertOk();

        $this->postJson('/api/auth/password/reset', [
            'phone' => '+201234567890',
            'code' => '000000',
            'password' => 'new-password123',
            'password_confirmation' => 'new-password123',
        ])->assertStatus(422)->assertJsonValidationErrors('code');

        $this->assertTrue(Hash::check('old-password', User::first()->password));
    }

    public function test_password_reset_attempts_are_limited_per_source_and_phone(): void
    {
        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $phone = '+20120000'.str_pad((string) $attempt, 4, '0', STR_PAD_LEFT);

            $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.40'])
                ->postJson('/api/auth/password/reset', $this->resetPayload($phone))
                ->assertStatus(422);
        }

        $sourceLimited = $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.40'])
            ->postJson('/api/auth/password/reset', $this->resetPayload('+201200009999'));

        $this->assertStableThrottleResponse($sourceLimited, 5);

        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $this->withServerVariables(['REMOTE_ADDR' => "203.0.113.{$attempt}"])
                ->postJson('/api/auth/password/reset', $this->resetPayload(' +201234567890 '))
                ->assertStatus(422);
        }

        $phoneLimited = $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.99'])
            ->postJson('/api/auth/password/reset', $this->resetPayload('+201234567890'));

        $this->assertStableThrottleResponse($phoneLimited, 5);
    }

    public function test_a_reset_code_cannot_be_reused(): void
    {
        $sms = $this->fakeSms();
        User::factory()->create(['phone' => '+201234567890', 'password' => 'old-password']);
        $this->postJson('/api/auth/password/forgot', ['phone' => '+201234567890'])->assertOk();
        $code = $sms->latestCodeFor('+201234567890');

        $this->postJson('/api/auth/password/reset', [
            'phone' => '+201234567890', 'code' => $code,
            'password' => 'new-password123', 'password_confirmation' => 'new-password123',
        ])->assertOk();

        $this->postJson('/api/auth/password/reset', [
            'phone' => '+201234567890', 'code' => $code,
            'password' => 'another-password123', 'password_confirmation' => 'another-password123',
        ])->assertStatus(422);
    }

    public function test_the_new_password_must_be_valid_and_confirmed(): void
    {
        $sms = $this->fakeSms();
        User::factory()->create(['phone' => '+201234567890']);
        $this->postJson('/api/auth/password/forgot', ['phone' => '+201234567890'])->assertOk();

        $this->postJson('/api/auth/password/reset', [
            'phone' => '+201234567890',
            'code' => $sms->latestCodeFor('+201234567890'),
            'password' => 'short',
            'password_confirmation' => 'mismatch',
        ])->assertStatus(422)->assertJsonValidationErrors('password');
    }

    public function test_forgot_password_does_not_reveal_whether_the_phone_exists(): void
    {
        $sms = $this->fakeSms();

        $this->postJson('/api/auth/password/forgot', ['phone' => '+209999999999'])
            ->assertOk()
            ->assertJsonPath('message', 'If the phone number is registered, a reset code has been sent.');

        $this->assertSame(0, $sms->countFor('+209999999999'));
    }

    public function test_known_and_unknown_phones_receive_the_same_forgot_password_responses(): void
    {
        $this->freezeTime();
        $this->fakeSms();
        User::factory()->create(['phone' => '+201234567890']);

        $knownSuccess = $this->postJson('/api/auth/password/forgot', ['phone' => '+201234567890']);
        $unknownSuccess = $this->postJson('/api/auth/password/forgot', ['phone' => '+209999999999']);

        $this->assertSame($knownSuccess->getContent(), $unknownSuccess->getContent());
        $knownSuccess->assertOk();
        $unknownSuccess->assertOk();

        foreach (['+201234567890', '+209999999999'] as $phone) {
            $this->postJson('/api/auth/password/forgot', ['phone' => $phone])->assertOk();
            $this->postJson('/api/auth/password/forgot', ['phone' => $phone])->assertOk();
        }

        $knownThrottle = $this->postJson('/api/auth/password/forgot', ['phone' => '+201234567890']);
        $unknownThrottle = $this->postJson('/api/auth/password/forgot', ['phone' => '+209999999999']);

        $this->assertSame($knownThrottle->getContent(), $unknownThrottle->getContent());
        $this->assertSame(
            $knownThrottle->headers->get('Retry-After'),
            $unknownThrottle->headers->get('Retry-After'),
        );
        $this->assertStableThrottleResponse($knownThrottle, 3);
        $this->assertStableThrottleResponse($unknownThrottle, 3);
    }

    public function test_the_internal_issue_guard_does_not_reveal_a_known_phone(): void
    {
        $this->fakeSms();
        User::factory()->create(['phone' => '+201234567890']);

        for ($attempt = 1; $attempt <= (int) config('store.otp.max_per_window'); $attempt++) {
            OtpCode::create([
                'phone' => '+201234567890',
                'code_hash' => 'stored-code-hash',
                'purpose' => OtpPurpose::PasswordReset,
                'expires_at' => now()->addMinutes(10),
            ]);
        }

        $known = $this->postJson('/api/auth/password/forgot', ['phone' => '+201234567890']);
        $unknown = $this->postJson('/api/auth/password/forgot', ['phone' => '+209999999999']);

        $known->assertOk();
        $unknown->assertOk();
        $this->assertSame($known->getContent(), $unknown->getContent());
    }

    public function test_forgot_password_is_rate_limited_per_phone_and_source(): void
    {
        $this->fakeSms();

        for ($attempt = 1; $attempt <= 3; $attempt++) {
            $this->withServerVariables(['REMOTE_ADDR' => "198.51.100.{$attempt}"])
                ->postJson('/api/auth/password/forgot', ['phone' => '+209999999999'])
                ->assertOk();
        }

        $phoneLimited = $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.99'])
            ->postJson('/api/auth/password/forgot', ['phone' => '+209999999999']);

        $this->assertStableThrottleResponse($phoneLimited, 3);

        for ($attempt = 1; $attempt <= 20; $attempt++) {
            $phone = '+20130000'.str_pad((string) $attempt, 4, '0', STR_PAD_LEFT);

            $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.20'])
                ->postJson('/api/auth/password/forgot', ['phone' => $phone])
                ->assertOk();
        }

        $sourceLimited = $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.20'])
            ->postJson('/api/auth/password/forgot', ['phone' => '+201300009999']);

        $this->assertStableThrottleResponse($sourceLimited, 20);
    }

    public function test_verification_and_password_reset_limiters_use_separate_purpose_buckets(): void
    {
        $this->fakeSms();

        for ($attempt = 1; $attempt <= 3; $attempt++) {
            $this->postJson('/api/auth/verify-phone/request', ['phone' => '+209999999999'])
                ->assertOk();
        }

        $this->postJson('/api/auth/password/forgot', ['phone' => '+209999999999'])
            ->assertOk();

        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $this->postJson('/api/auth/verify-phone', [
                'phone' => '+208888888888',
                'code' => '000000',
            ])->assertStatus(422);
        }

        $this->postJson('/api/auth/password/reset', $this->resetPayload('+208888888888'))
            ->assertStatus(422);
    }

    public function test_a_verification_code_cannot_be_used_to_reset_a_password(): void
    {
        $sms = $this->fakeSms();
        User::factory()->unverified()->create(['phone' => '+201234567890', 'password' => 'old-password']);

        // Issue a *phone verification* code, then try to spend it on a reset.
        $this->postJson('/api/auth/verify-phone/request', ['phone' => '+201234567890'])->assertOk();

        $this->postJson('/api/auth/password/reset', [
            'phone' => '+201234567890',
            'code' => $sms->latestCodeFor('+201234567890'),
            'password' => 'new-password123',
            'password_confirmation' => 'new-password123',
        ])->assertStatus(422);

        $this->assertTrue(Hash::check('old-password', User::first()->password));
    }

    private function resetPayload(string $phone): array
    {
        return [
            'phone' => $phone,
            'code' => '000000',
            'password' => 'new-password123',
            'password_confirmation' => 'new-password123',
        ];
    }

    private function assertStableThrottleResponse($response, int $limit): void
    {
        $response->assertStatus(429)
            ->assertExactJson([
                'message' => 'Too many attempts. Please try again later.',
            ])
            ->assertHeader('Retry-After')
            ->assertHeader('X-RateLimit-Limit', (string) $limit)
            ->assertHeader('X-RateLimit-Remaining', '0');
    }
}
