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

    public function test_login_is_rate_limited_to_five_attempts_per_source(): void
    {
        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.10'])
                ->postJson('/api/auth/login', [
                    'phone' => '+2010000000'.str_pad((string) $attempt, 2, '0', STR_PAD_LEFT),
                    'password' => 'wrong-password',
                ])->assertStatus(422);
        }

        $response = $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.10'])
            ->postJson('/api/auth/login', [
                'phone' => '+201000000099',
                'password' => 'wrong-password',
            ]);

        $this->assertStableThrottleResponse($response, 5);
    }

    public function test_login_is_rate_limited_to_five_attempts_per_trimmed_phone_across_sources(): void
    {
        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $this->withServerVariables(['REMOTE_ADDR' => "198.51.100.{$attempt}"])
                ->postJson('/api/auth/login', [
                    'phone' => ' +201234567890 ',
                    'password' => 'wrong-password',
                ])->assertStatus(422);
        }

        $response = $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.99'])
            ->postJson('/api/auth/login', [
                'phone' => '+201234567890',
                'password' => 'wrong-password',
            ]);

        $this->assertStableThrottleResponse($response, 5);
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

    public function test_a_user_can_fetch_their_own_profile(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->getJson('/api/auth/me')
            ->assertOk()
            ->assertJsonPath('user.id', $user->id)
            ->assertJsonPath('user.phone', $user->phone);
    }

    public function test_fetching_the_profile_requires_authentication(): void
    {
        $this->getJson('/api/auth/me')->assertStatus(401);
    }

    public function test_logout_all_revokes_every_token_for_the_user(): void
    {
        $user = User::factory()->create([
            'phone' => '+201234567890',
            'password' => 'password123',
        ]);

        $tokenA = $this->postJson('/api/auth/login', [
            'phone' => '+201234567890',
            'password' => 'password123',
        ])->json('token');

        $tokenB = $this->postJson('/api/auth/login', [
            'phone' => '+201234567890',
            'password' => 'password123',
        ])->json('token');

        $this->withToken($tokenA)->postJson('/api/auth/logout-all')->assertOk();

        $this->app['auth']->forgetGuards();
        $this->withToken($tokenA)->getJson('/api/auth/me')->assertStatus(401);

        $this->app['auth']->forgetGuards();
        $this->withToken($tokenB)->getJson('/api/auth/me')->assertStatus(401);
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
