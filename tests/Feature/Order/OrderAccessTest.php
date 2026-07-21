<?php

namespace Tests\Feature\Order;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrderAccessTest extends TestCase
{
    use RefreshDatabase;

    private function orderFor(User $user): Order
    {
        return Order::create([
            'user_id' => $user->id,
            'status' => OrderStatus::Pending,
            'total' => 10,
        ]);
    }

    public function test_a_user_sees_only_their_own_orders_in_the_list(): void
    {
        $me = User::factory()->create();
        $other = User::factory()->create();
        $this->orderFor($me);
        $this->orderFor($other);

        $this->actingAs($me)->getJson('/api/orders')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.user_id', $me->id);
    }

    public function test_an_admin_sees_all_orders(): void
    {
        $admin = User::factory()->admin()->create();
        $this->orderFor(User::factory()->create());
        $this->orderFor(User::factory()->create());

        $this->actingAs($admin)->getJson('/api/orders')
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    public function test_a_user_cannot_view_another_users_order(): void
    {
        $me = User::factory()->create();
        $other = User::factory()->create();
        $theirOrder = $this->orderFor($other);

        $this->actingAs($me)->getJson("/api/orders/{$theirOrder->id}")
            ->assertForbidden();
    }

    public function test_a_user_can_view_their_own_order(): void
    {
        $me = User::factory()->create();
        $order = $this->orderFor($me);

        $this->actingAs($me)->getJson("/api/orders/{$order->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $order->id);
    }

    public function test_an_admin_can_view_any_order(): void
    {
        $admin = User::factory()->admin()->create();
        $order = $this->orderFor(User::factory()->create());

        $this->actingAs($admin)->getJson("/api/orders/{$order->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $order->id);
    }

    public function test_listing_orders_requires_authentication(): void
    {
        $this->getJson('/api/orders')->assertUnauthorized();
    }
}
