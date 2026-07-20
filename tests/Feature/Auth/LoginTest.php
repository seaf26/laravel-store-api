<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LoginTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_verified_user_can_log_in_and_receive_a_token(): void
    {
        $user = User::factory()->create([
            'phone' => '+201234567890',
            'password' => 'password123',
        ]);

        $response = $this->postJson('/api/auth/login', [
            'phone' => '+201234567890',
            'password' => 'password123',
        ]);

        $response->assertOk()
            ->assertJsonStructure(['token', 'user' => ['id', 'phone']])
            ->assertJsonPath('user.id', $user->id);
    }

    public function test_login_fails_with_the_wrong_password(): void
    {
        User::factory()->create([
            'phone' => '+201234567890',
            'password' => 'password123',
        ]);

        $response = $this->postJson('/api/auth/login', [
            'phone' => '+201234567890',
            'password' => 'wrong-password',
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('phone');
    }

    public function test_an_unverified_user_cannot_log_in(): void
    {
        User::factory()->unverified()->create([
            'phone' => '+201234567890',
            'password' => 'password123',
        ]);

        $response = $this->postJson('/api/auth/login', [
            'phone' => '+201234567890',
            'password' => 'password123',
        ]);

        $response->assertStatus(403)
            ->assertJsonPath('message', 'Phone number is not verified.');
    }

    public function test_a_user_can_log_out_and_the_token_is_revoked(): void
    {
        $user = User::factory()->create([
            'phone' => '+201234567890',
            'password' => 'password123',
        ]);

        $token = $this->postJson('/api/auth/login', [
            'phone' => '+201234567890',
            'password' => 'password123',
        ])->json('token');

        $this->withToken($token)->postJson('/api/auth/logout')->assertOk();

        // Forget the guard the test container cached during the first request so
        // the next call re-resolves the (now deleted) token, as a fresh HTTP
        // request would. The revoked token can no longer authenticate.
        $this->app['auth']->forgetGuards();
        $this->withToken($token)->postJson('/api/auth/logout')->assertStatus(401);
    }

    public function test_protected_routes_reject_unauthenticated_requests(): void
    {
        $this->postJson('/api/auth/logout')->assertStatus(401);
    }
}
