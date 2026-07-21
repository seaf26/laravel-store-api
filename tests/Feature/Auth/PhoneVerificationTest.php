<?php

namespace Tests\Feature\Auth;

use App\Enums\OtpPurpose;
use App\Models\OtpCode;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PhoneVerificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_registering_sends_a_verification_code(): void
    {
        $sms = $this->fakeSms();

        $this->postJson('/api/auth/register', [
            'name' => 'Sara Ali',
            'phone' => '+201234567890',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ])->assertCreated();

        $this->assertSame(1, $sms->countFor('+201234567890'));
        // The code itself is stored only as a hash.
        $this->assertNotSame($sms->latestCodeFor('+201234567890'), OtpCode::first()->code_hash);
    }

    public function test_a_user_can_verify_their_phone_and_then_log_in(): void
    {
        $sms = $this->fakeSms();

        $this->postJson('/api/auth/register', [
            'name' => 'Sara Ali',
            'phone' => '+201234567890',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ])->assertCreated();

        // Login is blocked until the phone is verified.
        $this->postJson('/api/auth/login', [
            'phone' => '+201234567890',
            'password' => 'password123',
        ])->assertStatus(403);

        $this->postJson('/api/auth/verify-phone', [
            'phone' => '+201234567890',
            'code' => $sms->latestCodeFor('+201234567890'),
        ])->assertOk();

        $this->assertNotNull(User::first()->phone_verified_at);

        $this->postJson('/api/auth/login', [
            'phone' => '+201234567890',
            'password' => 'password123',
        ])->assertOk()->assertJsonStructure(['token']);
    }

    public function test_the_verification_response_never_exposes_the_code(): void
    {
        $this->fakeSms();
        User::factory()->unverified()->create(['phone' => '+201234567890']);

        $response = $this->postJson('/api/auth/verify-phone/request', ['phone' => '+201234567890']);

        $response->assertOk();
        $this->assertStringNotContainsString(
            (string) OtpCode::first()->code_hash,
            $response->getContent()
        );
        $response->assertJsonMissingPath('code');
    }

    public function test_an_incorrect_code_is_rejected(): void
    {
        $this->fakeSms();
        User::factory()->unverified()->create(['phone' => '+201234567890']);
        $this->postJson('/api/auth/verify-phone/request', ['phone' => '+201234567890'])->assertOk();

        $this->postJson('/api/auth/verify-phone', [
            'phone' => '+201234567890',
            'code' => '000000',
        ])->assertStatus(422)->assertJsonValidationErrors('code');

        $this->assertNull(User::first()->phone_verified_at);
    }

    public function test_an_expired_code_is_rejected(): void
    {
        $sms = $this->fakeSms();
        User::factory()->unverified()->create(['phone' => '+201234567890']);
        $this->postJson('/api/auth/verify-phone/request', ['phone' => '+201234567890'])->assertOk();
        $code = $sms->latestCodeFor('+201234567890');

        $this->travel(config('store.otp.ttl_minutes') + 1)->minutes();

        $this->postJson('/api/auth/verify-phone', [
            'phone' => '+201234567890',
            'code' => $code,
        ])->assertStatus(422);
    }

    public function test_a_code_cannot_be_used_twice(): void
    {
        $sms = $this->fakeSms();
        $user = User::factory()->unverified()->create(['phone' => '+201234567890']);
        $this->postJson('/api/auth/verify-phone/request', ['phone' => '+201234567890'])->assertOk();
        $code = $sms->latestCodeFor('+201234567890');

        $this->postJson('/api/auth/verify-phone', ['phone' => '+201234567890', 'code' => $code])->assertOk();

        // Re-submitting the consumed code fails.
        $this->postJson('/api/auth/verify-phone', ['phone' => '+201234567890', 'code' => $code])
            ->assertStatus(422);
    }

    public function test_requesting_a_new_code_invalidates_the_previous_one(): void
    {
        $sms = $this->fakeSms();
        User::factory()->unverified()->create(['phone' => '+201234567890']);

        $this->postJson('/api/auth/verify-phone/request', ['phone' => '+201234567890'])->assertOk();
        $firstCode = $sms->latestCodeFor('+201234567890');

        $this->postJson('/api/auth/verify-phone/request', ['phone' => '+201234567890'])->assertOk();

        $this->postJson('/api/auth/verify-phone', ['phone' => '+201234567890', 'code' => $firstCode])
            ->assertStatus(422);
    }

    public function test_code_requests_are_rate_limited_per_phone(): void
    {
        $this->fakeSms();
        User::factory()->unverified()->create(['phone' => '+201234567890']);

        $max = (int) config('store.otp.max_per_window');

        for ($i = 0; $i < $max; $i++) {
            $this->postJson('/api/auth/verify-phone/request', ['phone' => '+201234567890'])->assertOk();
        }

        $this->postJson('/api/auth/verify-phone/request', ['phone' => '+201234567890'])
            ->assertStatus(429);
    }

    public function test_requesting_a_code_does_not_reveal_whether_the_phone_exists(): void
    {
        $sms = $this->fakeSms();

        $unknown = $this->postJson('/api/auth/verify-phone/request', ['phone' => '+209999999999']);
        $unknown->assertOk();

        // No message is actually delivered for an unknown number.
        $this->assertSame(0, $sms->countFor('+209999999999'));
        $this->assertSame(0, OtpCode::where('purpose', OtpPurpose::PhoneVerification)->count());
    }
}
