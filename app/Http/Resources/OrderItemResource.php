<?php

namespace App\Http\Resources;

use App\Models\OrderItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin OrderItem
 */
class OrderItemResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'product_id' => $this->product_id,
            'title' => $this->whenLoaded('product', fn () => $this->product->title),
            'quantity' => $this->quantity,
            'unit_price' => $this->unit_price,
            'line_total' => bcmul((string) $this->unit_price, (string) $this->quantity, 2),
        ];
    }
}
