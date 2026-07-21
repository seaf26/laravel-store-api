<?php

namespace App\Notifications;

use App\Models\OrderStatusHistory;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class OrderStatusChangedNotification extends Notification
{
    use Queueable;

    public function __construct(public readonly OrderStatusHistory $history) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'order_status_changed',
            'order_id' => $this->history->order_id,
            'history_id' => $this->history->id,
            'from_status' => $this->history->from_status?->value,
            'to_status' => $this->history->to_status->value,
            'message' => "Your order #{$this->history->order_id} is now {$this->history->to_status->value}.",
        ];
    }
}
