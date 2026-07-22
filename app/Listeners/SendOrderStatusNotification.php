<?php

namespace App\Listeners;

use App\Events\OrderStatusChanged;
use App\Notifications\OrderStatusChangedNotification;
use App\Services\Notifications\NotificationDeduplicator;
use Illuminate\Contracts\Queue\ShouldQueue;

class SendOrderStatusNotification implements ShouldQueue
{
    public function __construct(private readonly NotificationDeduplicator $deduplicator) {}

    /**
     * Runs after the status change has committed, so a notification failure can
     * never roll the status change back.
     */
    public bool $afterCommit = true;

    public function handle(OrderStatusChanged $event): void
    {
        $history = $event->history;
        $order = $history->order;

        $this->deduplicator->sendOnce(
            $order->user,
            new OrderStatusChangedNotification($history),
            "order-status-history:{$history->id}",
        );
    }
}
