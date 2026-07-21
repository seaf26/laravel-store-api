<?php

namespace App\Listeners;

use App\Events\ProductCreated;
use App\Models\User;
use App\Notifications\NewProductNotification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Notification;

class SendNewProductNotifications implements ShouldQueue
{
    /**
     * Run only after the surrounding transaction commits, so the listener never
     * fires for a product that was rolled back.
     */
    public bool $afterCommit = true;

    public function handle(ProductCreated $event): void
    {
        $product = $event->product;

        // Verified, non-admin customers are the audience. Users who already hold
        // a notification for this product are excluded, so re-running the job
        // (a retry after a partial failure) never produces a duplicate.
        User::query()
            ->where('is_admin', false)
            ->whereNotNull('phone_verified_at')
            ->whereDoesntHave('notifications', function ($query) use ($product) {
                $query->where('type', NewProductNotification::class)
                    ->where('data->product_id', $product->id);
            })
            ->chunkById(500, function ($users) use ($product) {
                Notification::send($users, new NewProductNotification($product));
            });
    }
}
