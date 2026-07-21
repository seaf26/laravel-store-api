<?php

namespace Tests\Feature\Notification;

use App\Models\Product;
use App\Models\User;
use App\Notifications\NewProductNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class NotificationControllerTest extends TestCase
{
    use RefreshDatabase;

    private function notifyUser(User $user): Product
    {
        $product = Product::factory()->create();
        $user->notify(new NewProductNotification($product));

        return $product;
    }

    public function test_a_user_only_sees_their_own_notifications(): void
    {
        $me = User::factory()->create();
        $other = User::factory()->create();
        $this->notifyUser($me);
        $this->notifyUser($other);

        $this->actingAs($me)->getJson('/api/notifications')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.data.type', 'new_product');
    }

    public function test_listing_notifications_requires_authentication(): void
    {
        $this->getJson('/api/notifications')->assertUnauthorized();
    }

    public function test_per_page_is_clamped_to_a_safe_range(): void
    {
        $user = User::factory()->create();
        $this->notifyUser($user);

        // Below the floor: clamped up to 1.
        $this->actingAs($user)->getJson('/api/notifications?per_page=0')
            ->assertOk()
            ->assertJsonPath('meta.per_page', 1);

        // Above the ceiling: clamped down to 100.
        $this->actingAs($user)->getJson('/api/notifications?per_page=500')
            ->assertOk()
            ->assertJsonPath('meta.per_page', 100);

        // A sane value in range is respected as-is.
        $this->actingAs($user)->getJson('/api/notifications?per_page=5')
            ->assertOk()
            ->assertJsonPath('meta.per_page', 5);
    }

    public function test_the_response_uses_the_same_pagination_shape_as_products_and_orders(): void
    {
        $user = User::factory()->create();
        $this->notifyUser($user);

        $this->actingAs($user)->getJson('/api/notifications')
            ->assertOk()
            ->assertJsonStructure([
                'data',
                'links' => ['first', 'last', 'prev', 'next'],
                'meta' => ['current_page', 'last_page', 'per_page', 'total'],
            ]);
    }

    public function test_a_user_can_mark_their_own_notification_as_read(): void
    {
        $user = User::factory()->create();
        $this->notifyUser($user);
        $notification = $user->notifications()->firstOrFail();
        $this->assertNull($notification->read_at);

        $this->actingAs($user)->patchJson("/api/notifications/{$notification->id}/read")
            ->assertOk()
            ->assertJson(['message' => 'Notification marked as read.']);

        $this->assertNotNull($notification->fresh()->read_at);
    }

    public function test_a_user_cannot_mark_another_users_notification_as_read(): void
    {
        $me = User::factory()->create();
        $other = User::factory()->create();
        $this->notifyUser($other);
        $theirNotification = $other->notifications()->firstOrFail();

        $this->actingAs($me)->patchJson("/api/notifications/{$theirNotification->id}/read")
            ->assertNotFound();

        // The other user's notification must remain untouched.
        $this->assertNull($theirNotification->fresh()->read_at);
    }

    public function test_marking_an_already_read_notification_as_read_again_is_a_no_op(): void
    {
        $user = User::factory()->create();
        $this->notifyUser($user);
        $notification = $user->notifications()->firstOrFail();
        $notification->markAsRead();
        $firstReadAt = $notification->fresh()->read_at;

        $this->actingAs($user)->patchJson("/api/notifications/{$notification->id}/read")
            ->assertOk();

        // Still read, and reading it again didn't error or need special-casing.
        $this->assertNotNull($notification->fresh()->read_at);
        $this->assertTrue($firstReadAt->equalTo($notification->fresh()->read_at));
    }

    public function test_marking_an_unknown_notification_as_read_returns_404(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->patchJson('/api/notifications/'.Str::uuid().'/read')
            ->assertNotFound();
    }

    public function test_marking_a_notification_as_read_requires_authentication(): void
    {
        $user = User::factory()->create();
        $this->notifyUser($user);
        $notification = $user->notifications()->firstOrFail();

        $this->patchJson("/api/notifications/{$notification->id}/read")
            ->assertUnauthorized();
    }
}
