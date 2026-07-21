<?php

namespace App\Listeners;

use App\Events\ProductRestocked;
use App\Models\StockSubscription;
use App\Models\User;
use App\Notifications\BackInStockNotification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Notification;

class SendBackInStockNotifications implements ShouldQueue
{
    public bool $afterCommit = true;

    public function handle(ProductRestocked $event): void
    {
        $product = $event->product;

        $product->stockSubscriptions()
            ->select('id', 'user_id')
            ->chunkById(500, function ($subscriptions) use ($product) {
                $claimedUserIds = [];

                foreach ($subscriptions as $subscription) {
                    // Claim the subscription by deleting it first. The DELETE is
                    // atomic and returns the number of rows removed, so only the
                    // worker that actually removed the row goes on to notify.
                    // A retry (or a concurrent worker) finds the row already
                    // gone, so the same user is never notified twice.
                    if (StockSubscription::whereKey($subscription->id)->delete()) {
                        $claimedUserIds[] = $subscription->user_id;
                    }
                }

                if ($claimedUserIds !== []) {
                    Notification::send(
                        User::whereIn('id', $claimedUserIds)->get(),
                        new BackInStockNotification($product),
                    );
                }
            });
    }
}
