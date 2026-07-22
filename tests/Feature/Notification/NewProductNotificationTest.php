<?php

namespace Tests\Feature\Notification;

use App\Events\ProductCreated;
use App\Listeners\SendNewProductNotifications;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class NewProductNotificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_creating_a_product_dispatches_the_event_without_notifying_inline(): void
    {
        Storage::fake('public');
        Event::fake();

        $this->actingAs(User::factory()->admin()->create())->postJson('/api/products', [
            'title' => 'New Thing',
            'price' => 9.99,
            'description' => 'A new thing.',
            'stock' => 5,
            'image' => UploadedFile::fake()->image('t.jpg'),
        ])->assertCreated();

        // The request only dispatches the event; the actual fan-out runs on the
        // queued listener, so the response is never blocked by it.
        Event::assertDispatched(ProductCreated::class);
        Event::assertListening(ProductCreated::class, SendNewProductNotifications::class);
    }

    public function test_the_listener_notifies_only_verified_regular_users(): void
    {
        $verified = User::factory()->create();
        $unverified = User::factory()->unverified()->create();
        $admin = User::factory()->admin()->create();

        $product = Product::factory()->create();

        app(SendNewProductNotifications::class)->handle(new ProductCreated($product));

        $this->assertCount(1, $verified->notifications);
        $this->assertCount(0, $unverified->fresh()->notifications);
        $this->assertCount(0, $admin->fresh()->notifications);

        $this->assertSame('new_product', $verified->notifications->first()->data['type']);
        $this->assertSame($product->id, $verified->notifications->first()->data['product_id']);
    }

    public function test_running_the_listener_twice_does_not_duplicate_notifications(): void
    {
        $user = User::factory()->create();
        $product = Product::factory()->create();

        $listener = app(SendNewProductNotifications::class);
        $listener->handle(new ProductCreated($product));
        $listener->handle(new ProductCreated($product));

        // A retry of the job must not send the notification a second time.
        $this->assertCount(1, $user->fresh()->notifications);
    }

    public function test_a_user_only_sees_their_own_notifications(): void
    {
        $me = User::factory()->create();
        $other = User::factory()->create();
        $product = Product::factory()->create();

        app(SendNewProductNotifications::class)->handle(new ProductCreated($product));

        $this->actingAs($me)->getJson('/api/notifications')
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->assertDatabaseCount('notifications', 2);
    }
}
