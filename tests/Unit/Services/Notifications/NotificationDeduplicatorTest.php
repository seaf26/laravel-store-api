<?php

namespace Tests\Unit\Services\Notifications;

use App\Models\Product;
use App\Models\User;
use App\Notifications\NewProductNotification;
use App\Services\Notifications\NotificationDeduplicator;
use PHPUnit\Framework\TestCase;

class NotificationDeduplicatorTest extends TestCase
{
    public function test_the_same_recipient_event_and_notification_produce_the_same_uuid(): void
    {
        $service = new NotificationDeduplicator;
        $user = (new User)->forceFill(['id' => 42]);
        $notification = new NewProductNotification((new Product)->forceFill(['id' => 7]));

        $first = $service->idFor($user, $notification, 'new-product:7');
        $second = $service->idFor($user, $notification, 'new-product:7');

        $this->assertSame($first, $second);
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-5[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
            $first,
        );
    }

    public function test_recipient_or_event_changes_produce_different_ids(): void
    {
        $service = new NotificationDeduplicator;
        $product = (new Product)->forceFill(['id' => 7]);
        $notification = new NewProductNotification($product);
        $firstUser = (new User)->forceFill(['id' => 1]);
        $secondUser = (new User)->forceFill(['id' => 2]);

        $this->assertNotSame(
            $service->idFor($firstUser, $notification, 'new-product:7'),
            $service->idFor($secondUser, $notification, 'new-product:7'),
        );
        $this->assertNotSame(
            $service->idFor($firstUser, $notification, 'new-product:7'),
            $service->idFor($firstUser, $notification, 'new-product:8'),
        );
    }
}
