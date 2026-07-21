<?php

namespace Tests\Feature\Order;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrderListingTest extends TestCase
{
    use RefreshDatabase;

    private function order(User $user, OrderStatus $status, float $total): Order
    {
        return Order::create(['user_id' => $user->id, 'status' => $status, 'total' => $total]);
    }

    public function test_a_user_can_filter_their_orders_by_status(): void
    {
        $user = User::factory()->create();
        $this->order($user, OrderStatus::Pending, 10);
        $this->order($user, OrderStatus::Confirmed, 20);

        $response = $this->actingAs($user)->getJson('/api/orders?status=confirmed')->assertOk();

        $this->assertCount(1, $response->json('data'));
        $this->assertSame('confirmed', $response->json('data.0.status'));
    }

    public function test_an_admin_can_filter_orders_by_user(): void
    {
        $admin = User::factory()->admin()->create();
        $a = User::factory()->create();
        $b = User::factory()->create();
        $this->order($a, OrderStatus::Pending, 10);
        $this->order($b, OrderStatus::Pending, 10);

        $response = $this->actingAs($admin)->getJson("/api/orders?user_id={$a->id}")->assertOk();

        $this->assertCount(1, $response->json('data'));
        $this->assertSame($a->id, $response->json('data.0.user_id'));
    }

    public function test_orders_can_be_sorted_by_total(): void
    {
        $user = User::factory()->create();
        $this->order($user, OrderStatus::Pending, 30);
        $this->order($user, OrderStatus::Pending, 10);
        $this->order($user, OrderStatus::Pending, 20);

        $totals = collect(
            $this->actingAs($user)->getJson('/api/orders?sort=total&direction=asc')->json('data')
        )->pluck('total')->all();

        $this->assertSame(['10.00', '20.00', '30.00'], $totals);
    }

    public function test_an_invalid_order_sort_field_is_rejected(): void
    {
        $this->actingAs(User::factory()->create())
            ->getJson('/api/orders?sort=user_id')
            ->assertStatus(422)
            ->assertJsonValidationErrors('sort');
    }
}
