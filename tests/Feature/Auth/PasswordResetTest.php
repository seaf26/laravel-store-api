<?php

namespace Tests\Feature\Auth;

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
}
