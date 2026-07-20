<?php

namespace App\Notifications;

use App\Models\Product;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class BackInStockNotification extends Notification
{
    use Queueable;

    public function __construct(public readonly Product $product) {}

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
            'type' => 'back_in_stock',
            'product_id' => $this->product->id,
            'title' => $this->product->title,
            'message' => "{$this->product->title} is back in stock.",
        ];
    }
}
