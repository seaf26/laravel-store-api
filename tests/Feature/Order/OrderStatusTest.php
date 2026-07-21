<?php

namespace Tests\Feature\Order;

use App\Enums\OrderStatus;
use App\Events\OrderStatusChanged;
use App\Listeners\SendOrderStatusNotification;
use App\Models\Order;
use App\Models\User;
use App\Notifications\OrderStatusChangedNotification;
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

        $listener = new SendOrderStatusNotification;
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
}
