<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_user_can_register_with_a_phone_number(): void
    {
        $response = $this->postJson('/api/auth/register', [
            'name' => 'Sara Ali',
            'phone' => '+201234567890',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ]);

        $response->assertCreated()
            ->assertJsonPath('user.phone', '+201234567890')
            ->assertJsonPath('user.is_admin', false)
            ->assertJsonPath('user.phone_verified_at', null);

        $this->assertDatabaseHas('users', ['phone' => '+201234567890']);
        // The password must never be stored in clear text.
        $this->assertNotSame('password123', User::first()->password);
    }

    public function test_registration_requires_a_valid_phone(): void
    {
        $response = $this->postJson('/api/auth/register', [
            'name' => 'Sara Ali',
            'phone' => 'not-a-phone',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('phone');
    }

    public function test_registration_rejects_a_duplicate_phone(): void
    {
        User::factory()->create(['phone' => '+201234567890']);

        $response = $this->postJson('/api/auth/register', [
            'name' => 'Sara Ali',
            'phone' => '+201234567890',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('phone');
    }

    public function test_registration_rejects_a_weak_or_unconfirmed_password(): void
    {
        $response = $this->postJson('/api/auth/register', [
            'name' => 'Sara Ali',
            'phone' => '+201234567890',
            'password' => 'short',
            'password_confirmation' => 'mismatch',
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('password');
    }
}
