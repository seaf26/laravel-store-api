<?php

namespace App\Listeners;

use App\Events\OrderStatusChanged;
use App\Notifications\OrderStatusChangedNotification;
use Illuminate\Contracts\Queue\ShouldQueue;

class SendOrderStatusNotification implements ShouldQueue
{
    /**
     * Runs after the status change has committed, so a notification failure can
     * never roll the status change back.
     */
    public bool $afterCommit = true;

    public function handle(OrderStatusChanged $event): void
    {
        $history = $event->history;
        $order = $history->order;

        // De-duplicate on the history id: if a notification for this exact
        // change already exists, a retried job must not send it again.
        $alreadySent = $order->user
            ->notifications()
            ->where('type', OrderStatusChangedNotification::class)
            ->where('data->history_id', $history->id)
            ->exists();

        if ($alreadySent) {
            return;
        }

        $order->user->notify(new OrderStatusChangedNotification($history));
    }
}
