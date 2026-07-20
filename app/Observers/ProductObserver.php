<?php

namespace App\Observers;

use App\Events\ProductRestocked;
use App\Models\Product;

class ProductObserver
{
    /**
     * Detect the out-of-stock -> in-stock transition and raise an event.
     *
     * The observer's only job is detection; the notification work lives in the
     * event's listener.
     */
    public function updated(Product $product): void
    {
        if ($product->wasChanged('stock')
            && (int) $product->getOriginal('stock') === 0
            && $product->stock > 0) {
            ProductRestocked::dispatch($product);
        }
    }
}
