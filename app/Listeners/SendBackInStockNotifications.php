<?php

namespace App\Listeners;

use App\Events\ProductRestocked;
use App\Models\StockSubscription;
use App\Models\User;
use App\Notifications\BackInStockNotification;
use App\Services\Notifications\NotificationDeduplicator;
use Illuminate\Contracts\Queue\ShouldQueue;

class SendBackInStockNotifications implements ShouldQueue
{
    public bool $afterCommit = true;

    public function __construct(private readonly NotificationDeduplicator $deduplicator) {}

    public function handle(ProductRestocked $event): void
    {
        $product = $event->product;

        $product->stockSubscriptions()
            ->select('id', 'user_id')
            ->chunkById(500, function ($subscriptions) use ($product) {
                $users = User::whereIn('id', $subscriptions->pluck('user_id'))->get()->keyBy('id');

                foreach ($subscriptions as $subscription) {
                    $user = $users->get($subscription->user_id);

                    if (! $user) {
                        StockSubscription::whereKey($subscription->id)->delete();

                        continue;
                    }

                    // Keep the subscription until the database notification is
                    // either newly durable or confirmed as an existing duplicate.
                    $this->deduplicator->sendOnce(
                        $user,
                        new BackInStockNotification($product),
                        "back-in-stock-subscription:{$subscription->id}",
                    );

                    StockSubscription::whereKey($subscription->id)->delete();
                }
            });
    }
}
