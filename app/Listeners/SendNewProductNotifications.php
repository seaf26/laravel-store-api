<?php

namespace App\Listeners;

use App\Events\ProductCreated;
use App\Models\User;
use App\Notifications\NewProductNotification;
use App\Services\Notifications\NotificationDeduplicator;
use Illuminate\Contracts\Queue\ShouldQueue;

class SendNewProductNotifications implements ShouldQueue
{
    public function __construct(private readonly NotificationDeduplicator $deduplicator) {}

    /**
     * Run only after the surrounding transaction commits, so the listener never
     * fires for a product that was rolled back.
     */
    public bool $afterCommit = true;

    public function handle(ProductCreated $event): void
    {
        $product = $event->product;

        // Verified, non-admin customers are the audience. The deterministic
        // database notification id is the atomic claim for retries/races.
        User::query()
            ->where('is_admin', false)
            ->whereNotNull('phone_verified_at')
            ->chunkById(500, function ($users) use ($product) {
                foreach ($users as $user) {
                    $this->deduplicator->sendOnce(
                        $user,
                        new NewProductNotification($product),
                        "new-product:{$product->id}",
                    );
                }
            });
    }
}
