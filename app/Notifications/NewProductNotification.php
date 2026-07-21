<?php

namespace App\Notifications;

use App\Models\Product;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class NewProductNotification extends Notification
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
            'type' => 'new_product',
            'product_id' => $this->product->id,
            'title' => $this->product->title,
            'price' => $this->product->price,
            'message' => "New product available: {$this->product->title}.",
        ];
    }
}
