<?php

namespace Tests\Feature\Order;

use App\Enums\OrderStatus;
use App\Events\OrderStatusChanged;
use App\Listeners\SendOrderStatusNotification;
use App\Models\Order;
use App\Models\Product;
use App\Models\StockSubscription;
use App\Models\User;
use App\Services\Orders\OrderStatusService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class OrderStatusTest extends TestCase
{
    use RefreshDatabase;

    private function order(OrderStatus $status = OrderStatus::Pending): Order
    {
        return Order::create([
            'user_id' => User::factory()->create()->id,
            'status' => $status,
            'total' => 10,
        ]);
    }

    public function test_an_admin_can_advance_an_order_through_valid_transitions(): void
    {
        $admin = User::factory()->admin()->create();
        $order = $this->order(OrderStatus::Pending);

        foreach (['confirmed', 'processing', 'shipped', 'delivered'] as $next) {
            $this->actingAs($admin)
                ->patchJson("/api/orders/{$order->id}/status", ['status' => $next])
                ->assertOk()
                ->assertJsonPath('data.status', $next);
        }

        $this->assertSame('delivered', $order->fresh()->status->value);
    }

    public function test_invalid_transitions_are_rejected(): void
    {
        $admin = User::factory()->admin()->create();

        // pending -> shipped is not allowed
        $order = $this->order(OrderStatus::Pending);
        $this->actingAs($admin)
            ->patchJson("/api/orders/{$order->id}/status", ['status' => 'shipped'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Cannot change status from pending to shipped.');

        // delivered is terminal
        $delivered = $this->order(OrderStatus::Delivered);
        $this->actingAs($admin)
            ->patchJson("/api/orders/{$delivered->id}/status", ['status' => 'processing'])
            ->assertStatus(422);

        // cancelled is terminal
        $cancelled = $this->order(OrderStatus::Cancelled);
        $this->actingAs($admin)
            ->patchJson("/api/orders/{$cancelled->id}/status", ['status' => 'confirmed'])
            ->assertStatus(422);
    }

    public function test_an_unknown_status_value_is_a_validation_error(): void
    {
        $admin = User::factory()->admin()->create();
        $order = $this->order();

        $this->actingAs($admin)
            ->patchJson("/api/orders/{$order->id}/status", ['status' => 'nonsense'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('status');
    }

    public function test_resubmitting_the_current_status_is_a_no_op(): void
    {
        Event::fake([OrderStatusChanged::class]);
        $admin = User::factory()->admin()->create();
        $order = $this->order(OrderStatus::Pending);

        $this->actingAs($admin)
            ->patchJson("/api/orders/{$order->id}/status", ['status' => 'pending'])
            ->assertOk()
            ->assertJsonPath('message', 'Status unchanged.');

        // No history row, and no event (therefore no notification).
        $this->assertDatabaseCount('order_status_histories', 0);
        Event::assertNotDispatched(OrderStatusChanged::class);
    }

    public function test_a_real_change_records_history_with_actor_and_transition(): void
    {
        $admin = User::factory()->admin()->create();
        $order = $this->order(OrderStatus::Pending);

        $this->actingAs($admin)
            ->patchJson("/api/orders/{$order->id}/status", ['status' => 'confirmed'])
            ->assertOk();

        $this->assertDatabaseHas('order_status_histories', [
            'order_id' => $order->id,
            'from_status' => 'pending',
            'to_status' => 'confirmed',
            'changed_by' => $admin->id,
        ]);
    }

    public function test_the_order_owner_is_notified_on_a_real_change(): void
    {
        $owner = User::factory()->create();
        $order = Order::create(['user_id' => $owner->id, 'status' => OrderStatus::Pending, 'total' => 10]);
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->patchJson("/api/orders/{$order->id}/status", ['status' => 'confirmed'])
            ->assertOk();

        $this->assertCount(1, $owner->fresh()->notifications);
        $this->assertSame('order_status_changed', $owner->notifications->first()->data['type']);
        $this->assertSame('confirmed', $owner->notifications->first()->data['to_status']);
    }

    public function test_a_non_admin_cannot_change_status(): void
    {
        $order = $this->order();
        $user = User::factory()->create();

        $this->actingAs($user)
            ->patchJson("/api/orders/{$order->id}/status", ['status' => 'confirmed'])
            ->assertForbidden();

        $this->assertDatabaseCount('order_status_histories', 0);
    }

    public function test_changing_the_status_of_an_unknown_order_returns_404(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->patchJson('/api/orders/999999/status', ['status' => 'confirmed'])
            ->assertNotFound();
    }

    public function test_retrying_the_listener_does_not_send_a_duplicate_notification(): void
    {
        $owner = User::factory()->create();
        $order = Order::create(['user_id' => $owner->id, 'status' => OrderStatus::Pending, 'total' => 10]);
        $admin = User::factory()->admin()->create();

        $history = $order->statusHistories()->create([
            'from_status' => OrderStatus::Pending,
            'to_status' => OrderStatus::Confirmed,
            'changed_by' => $admin->id,
        ]);

        $listener = app(SendOrderStatusNotification::class);
        $listener->handle(new OrderStatusChanged($history));
        $listener->handle(new OrderStatusChanged($history));

        $this->assertCount(1, $owner->fresh()->notifications);
    }

    public function test_a_notification_failure_does_not_undo_the_status_change(): void
    {
        $owner = User::factory()->create();
        $order = Order::create(['user_id' => $owner->id, 'status' => OrderStatus::Pending, 'total' => 10]);
        $admin = User::factory()->admin()->create();

        // Make the queued listener throw when it runs.
        Event::listen(OrderStatusChanged::class, function () {
            throw new \RuntimeException('notification backend down');
        });

        try {
            $this->actingAs($admin)
                ->patchJson("/api/orders/{$order->id}/status", ['status' => 'confirmed']);
        } catch (\RuntimeException) {
            // The notification failed, but the change must have persisted.
        }

        $this->assertSame('confirmed', $order->fresh()->status->value);
        $this->assertDatabaseHas('order_status_histories', [
            'order_id' => $order->id,
            'to_status' => 'confirmed',
        ]);
    }

    public function test_cancelling_an_order_restocks_its_items(): void
    {
        $admin = User::factory()->admin()->create();
        $owner = User::factory()->create();
        $productA = Product::factory()->create(['stock' => 5]);
        $productB = Product::factory()->create(['stock' => 2]);

        $order = Order::create(['user_id' => $owner->id, 'status' => OrderStatus::Pending, 'total' => 0]);
        $order->items()->createMany([
            ['product_id' => $productA->id, 'quantity' => 3, 'unit_price' => $productA->price],
            ['product_id' => $productB->id, 'quantity' => 1, 'unit_price' => $productB->price],
        ]);

        $this->actingAs($admin)
            ->patchJson("/api/orders/{$order->id}/status", ['status' => 'cancelled'])
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled');

        $this->assertSame(8, $productA->fresh()->stock);
        $this->assertSame(3, $productB->fresh()->stock);
    }

    public function test_a_non_cancelling_transition_does_not_touch_stock(): void
    {
        $admin = User::factory()->admin()->create();
        $owner = User::factory()->create();
        $product = Product::factory()->create(['stock' => 5]);

        $order = Order::create(['user_id' => $owner->id, 'status' => OrderStatus::Pending, 'total' => 0]);
        $order->items()->create(['product_id' => $product->id, 'quantity' => 2, 'unit_price' => $product->price]);

        $this->actingAs($admin)
            ->patchJson("/api/orders/{$order->id}/status", ['status' => 'confirmed'])
            ->assertOk();

        $this->assertSame(5, $product->fresh()->stock);
    }

    public function test_cancelling_an_order_for_an_out_of_stock_product_triggers_the_restock_notification(): void
    {
        $admin = User::factory()->admin()->create();
        $owner = User::factory()->create();
        $subscriber = User::factory()->create();
        $product = Product::factory()->outOfStock()->create();
        StockSubscription::create(['user_id' => $subscriber->id, 'product_id' => $product->id]);

        $order = Order::create(['user_id' => $owner->id, 'status' => OrderStatus::Pending, 'total' => 0]);
        $order->items()->create(['product_id' => $product->id, 'quantity' => 3, 'unit_price' => $product->price]);

        $this->actingAs($admin)
            ->patchJson("/api/orders/{$order->id}/status", ['status' => 'cancelled'])
            ->assertOk();

        $this->assertSame(3, $product->fresh()->stock);
        $this->assertCount(1, $subscriber->fresh()->notifications);
        $this->assertSame('back_in_stock', $subscriber->notifications->first()->data['type']);
    }

    public function test_a_stale_order_is_validated_against_its_latest_committed_status(): void
    {
        Event::fake([OrderStatusChanged::class]);
        $admin = User::factory()->admin()->create();
        $order = $this->order(OrderStatus::Pending);
        $staleOrder = Order::findOrFail($order->id);

        $order->update(['status' => OrderStatus::Confirmed]);

        $history = app(OrderStatusService::class)->change(
            $staleOrder,
            OrderStatus::Processing,
            $admin,
        );

        $this->assertNotNull($history);
        $this->assertSame('confirmed', $history->from_status->value);
        $this->assertSame('processing', $order->fresh()->status->value);
    }

    public function test_a_stale_duplicate_cancellation_does_not_restock_or_write_history_twice(): void
    {
        Event::fake([OrderStatusChanged::class]);
        $admin = User::factory()->admin()->create();
        $product = Product::factory()->create(['stock' => 5]);
        $order = $this->order(OrderStatus::Pending);
        $order->items()->create([
            'product_id' => $product->id,
            'quantity' => 2,
            'unit_price' => $product->price,
        ]);
        $firstRequestOrder = Order::findOrFail($order->id);
        $staleDuplicateOrder = Order::findOrFail($order->id);
        $service = app(OrderStatusService::class);

        $this->assertNotNull($service->change($firstRequestOrder, OrderStatus::Cancelled, $admin));
        $this->assertNull($service->change($staleDuplicateOrder, OrderStatus::Cancelled, $admin));

        $this->assertSame(7, $product->fresh()->stock);
        $this->assertDatabaseCount('order_status_histories', 1);
    }

    public function test_a_no_op_response_refreshes_the_order_after_the_status_service_runs(): void
    {
        $admin = User::factory()->admin()->create();
        $order = $this->order(OrderStatus::Pending);
        $statusService = $this->mock(OrderStatusService::class);
        $statusService->shouldReceive('change')
            ->once()
            ->andReturnUsing(function (Order $staleOrder): null {
                Order::whereKey($staleOrder->id)->update(['status' => OrderStatus::Confirmed]);

                return null;
            });

        $this->actingAs($admin)
            ->patchJson("/api/orders/{$order->id}/status", ['status' => 'pending'])
            ->assertOk()
            ->assertJsonPath('message', 'Status unchanged.')
            ->assertJsonPath('data.status', 'confirmed');
    }
}
