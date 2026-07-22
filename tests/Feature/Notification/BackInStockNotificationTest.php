<?php

namespace Tests\Feature\Notification;

use App\Events\ProductRestocked;
use App\Listeners\SendBackInStockNotifications;
use App\Models\Product;
use App\Models\StockSubscription;
use App\Models\User;
use App\Notifications\BackInStockNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class BackInStockNotificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_sms_uses_a_valid_utf_8_em_dash(): void
    {
        $product = Product::factory()->make(['title' => 'Travel Mug']);
        $user = User::factory()->make();

        $message = (new BackInStockNotification($product))->toSms($user);

        $this->assertSame('Good news — Travel Mug is back in stock.', $message);
        $this->assertTrue(mb_check_encoding($message, 'UTF-8'));
    }

    public function test_a_user_can_subscribe_only_when_a_product_is_out_of_stock(): void
    {
        $user = User::factory()->create();
        $outOfStock = Product::factory()->outOfStock()->create();
        $inStock = Product::factory()->create(['stock' => 3]);

        $this->actingAs($user)->postJson("/api/products/{$outOfStock->id}/notify-me")
            ->assertCreated();
        $this->assertDatabaseHas('stock_subscriptions', [
            'user_id' => $user->id, 'product_id' => $outOfStock->id,
        ]);

        $this->actingAs($user)->postJson("/api/products/{$inStock->id}/notify-me")
            ->assertStatus(422)
            ->assertJsonPath('message', 'Product is in stock.');
    }

    public function test_subscribing_twice_does_not_create_a_duplicate(): void
    {
        $user = User::factory()->create();
        $product = Product::factory()->outOfStock()->create();

        $this->actingAs($user)->postJson("/api/products/{$product->id}/notify-me")->assertCreated();
        $this->actingAs($user)->postJson("/api/products/{$product->id}/notify-me")->assertOk();

        $this->assertDatabaseCount('stock_subscriptions', 1);
    }

    public function test_restocking_dispatches_the_event_only_on_the_zero_to_positive_transition(): void
    {
        // Fake only ProductRestocked so the Eloquent "updated" model event still
        // fires and the observer actually runs.
        Event::fake([ProductRestocked::class]);
        $product = Product::factory()->outOfStock()->create();

        // 0 -> 5 fires the event.
        $product->update(['stock' => 5]);
        Event::assertDispatched(ProductRestocked::class);

        Event::assertListening(ProductRestocked::class, SendBackInStockNotifications::class);
    }

    public function test_no_event_when_stock_changes_between_positive_values(): void
    {
        Event::fake([ProductRestocked::class]);
        $product = Product::factory()->create(['stock' => 5]);

        $product->update(['stock' => 7]);

        Event::assertNotDispatched(ProductRestocked::class);
    }

    public function test_selling_down_to_zero_does_not_trigger_a_restock(): void
    {
        Event::fake([ProductRestocked::class]);
        $product = Product::factory()->create(['stock' => 5]);

        $product->update(['stock' => 0]);

        Event::assertNotDispatched(ProductRestocked::class);
    }

    public function test_only_subscribers_are_notified_and_subscriptions_are_consumed(): void
    {
        $product = Product::factory()->outOfStock()->create();
        $subscriber = User::factory()->create();
        $bystander = User::factory()->create();

        StockSubscription::create(['user_id' => $subscriber->id, 'product_id' => $product->id]);

        // Restock and run the listener.
        $product->update(['stock' => 10]);
        app(SendBackInStockNotifications::class)->handle(new ProductRestocked($product));

        $this->assertCount(1, $subscriber->notifications);
        $this->assertSame('back_in_stock', $subscriber->notifications->first()->data['type']);
        $this->assertCount(0, $bystander->fresh()->notifications);

        // The subscription is consumed so a later restock won't re-notify.
        $this->assertDatabaseCount('stock_subscriptions', 0);
    }

    public function test_running_the_listener_twice_notifies_each_subscriber_once(): void
    {
        $product = Product::factory()->outOfStock()->create();
        $subscriber = User::factory()->create();
        StockSubscription::create(['user_id' => $subscriber->id, 'product_id' => $product->id]);
        $product->update(['stock' => 10]);

        $listener = app(SendBackInStockNotifications::class);
        $listener->handle(new ProductRestocked($product));
        // A retry must not send a second notification: the subscription was
        // already claimed (deleted) on the first run.
        $listener->handle(new ProductRestocked($product));

        $this->assertCount(1, $subscriber->fresh()->notifications);
    }

    public function test_subscription_requires_authentication(): void
    {
        $product = Product::factory()->outOfStock()->create();

        $this->postJson("/api/products/{$product->id}/notify-me")->assertUnauthorized();
    }

    public function test_end_to_end_admin_restock_notifies_the_subscriber(): void
    {
        $admin = User::factory()->admin()->create();
        $subscriber = User::factory()->create();
        $product = Product::factory()->outOfStock()->create();

        // Subscriber signs up while it is out of stock.
        $this->actingAs($subscriber)->postJson("/api/products/{$product->id}/notify-me")
            ->assertCreated();

        // Admin restocks through the real endpoint. Observer -> event -> queued
        // listener (sync in tests) -> database notification, all off the back of
        // a normal product update.
        $this->actingAs($admin)->patchJson("/api/products/{$product->id}", ['stock' => 8])
            ->assertOk();

        $this->assertCount(1, $subscriber->fresh()->notifications);
        $this->assertDatabaseCount('stock_subscriptions', 0);
    }
}
