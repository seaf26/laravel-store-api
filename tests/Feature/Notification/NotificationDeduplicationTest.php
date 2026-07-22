<?php

namespace Tests\Feature\Notification;

use App\Enums\OrderStatus;
use App\Events\OrderStatusChanged;
use App\Events\ProductRestocked;
use App\Listeners\SendBackInStockNotifications;
use App\Listeners\SendOrderStatusNotification;
use App\Models\Order;
use App\Models\Product;
use App\Models\StockSubscription;
use App\Models\User;
use App\Notifications\BackInStockNotification;
use App\Notifications\Channels\SmsChannel;
use App\Notifications\NewProductNotification;
use App\Notifications\OrderStatusChangedNotification;
use App\Services\Notifications\NotificationDeduplicator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class NotificationDeduplicationTest extends TestCase
{
    use RefreshDatabase;

    public function test_duplicate_delivery_is_a_clean_no_op(): void
    {
        $user = User::factory()->create();
        $product = Product::factory()->create();
        $service = app(NotificationDeduplicator::class);

        $this->assertTrue($service->sendOnce($user, new NewProductNotification($product), "new-product:{$product->id}"));
        $this->assertFalse($service->sendOnce($user, new NewProductNotification($product), "new-product:{$product->id}"));
        $this->assertCount(1, $user->fresh()->notifications);
    }

    public function test_database_delivery_precedes_sms_for_mixed_channel_notifications(): void
    {
        $user = User::factory()->make();
        $product = Product::factory()->make();
        $order = new Order(['status' => OrderStatus::Confirmed]);
        $history = $order->statusHistories()->make([
            'id' => 1,
            'from_status' => OrderStatus::Pending,
            'to_status' => OrderStatus::Confirmed,
        ]);

        $this->assertSame(['database', SmsChannel::class], (new BackInStockNotification($product))->via($user));
        $this->assertSame(['database', SmsChannel::class], (new OrderStatusChangedNotification($history))->via($user));
    }

    public function test_retrying_an_order_status_listener_sends_one_database_notification_and_one_sms(): void
    {
        $sms = $this->fakeSms();
        $owner = User::factory()->create();
        $admin = User::factory()->admin()->create();
        $order = Order::create(['user_id' => $owner->id, 'status' => OrderStatus::Confirmed, 'total' => 10]);
        $history = $order->statusHistories()->create([
            'from_status' => OrderStatus::Pending,
            'to_status' => OrderStatus::Confirmed,
            'changed_by' => $admin->id,
        ]);
        $listener = app(SendOrderStatusNotification::class);

        $listener->handle(new OrderStatusChanged($history));
        $listener->handle(new OrderStatusChanged($history));

        $this->assertCount(1, $owner->fresh()->notifications);
        $this->assertSame(1, $sms->countFor($owner->phone));
    }

    public function test_a_back_in_stock_subscription_remains_when_durable_delivery_fails(): void
    {
        $product = Product::factory()->outOfStock()->create();
        $user = User::factory()->create();
        StockSubscription::create(['user_id' => $user->id, 'product_id' => $product->id]);
        $deduplicator = Mockery::mock(NotificationDeduplicator::class);
        $deduplicator->shouldReceive('sendOnce')->once()->andThrow(new RuntimeException('database unavailable'));
        $listener = new SendBackInStockNotifications($deduplicator);

        $thrown = null;

        try {
            $listener->handle(new ProductRestocked($product));
        } catch (RuntimeException $exception) {
            $thrown = $exception;
        }

        $this->assertNotNull($thrown, 'Expected notification delivery to fail.');
        $this->assertDatabaseHas('stock_subscriptions', [
            'user_id' => $user->id,
            'product_id' => $product->id,
        ]);
    }
}
