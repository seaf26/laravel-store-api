<?php

namespace App\Services\Orders;

use App\Enums\OrderStatus;
use App\Exceptions\InsufficientStockException;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class OrderService
{
    /**
     * Place an order atomically.
     *
     * The whole operation runs in a single transaction with the product rows
     * locked for update. Either every requested quantity is available and the
     * order is created with stock decremented, or nothing changes at all.
     *
     * @param  array<int, array{product_id: int, quantity: int}>  $items
     *
     * @throws InsufficientStockException
     */
    public function place(User $user, array $items): Order
    {
        // Combine duplicate product lines into a single required quantity.
        $required = [];
        foreach ($items as $item) {
            $id = (int) $item['product_id'];
            $required[$id] = ($required[$id] ?? 0) + (int) $item['quantity'];
        }

        return DB::transaction(function () use ($user, $required) {
            // Lock the product rows in a stable id order. Ordering the locks
            // consistently means two concurrent orders acquire them in the same
            // sequence and cannot deadlock; the lock serialises the read-check-
            // decrement so stock can never be oversold.
            $products = Product::query()
                ->whereIn('id', array_keys($required))
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            $errors = $this->collectStockErrors($required, $products);

            if ($errors !== []) {
                // Throwing here rolls the transaction back: no order, no items,
                // no stock change.
                throw new InsufficientStockException($errors);
            }

            $order = $user->orders()->create([
                'status' => OrderStatus::Pending,
                'total' => 0,
            ]);

            $total = '0.00';

            foreach ($required as $productId => $quantity) {
                $product = $products->get($productId);

                $product->decrement('stock', $quantity);

                $order->items()->create([
                    'product_id' => $product->id,
                    'quantity' => $quantity,
                    'unit_price' => $product->price,
                ]);

                // Money is accumulated with bcmath to avoid float rounding.
                $total = bcadd($total, bcmul((string) $product->price, (string) $quantity, 2), 2);
            }

            $order->update(['total' => $total]);

            return $order->load('items.product');
        });
    }

    /**
     * Build a per-line error map for any product that cannot satisfy its
     * requested quantity. The line index matches the original request order.
     *
     * @param  array<int, int>  $required
     * @param  \Illuminate\Support\Collection<int, Product>  $products
     * @return array<string, array<int, string>>
     */
    private function collectStockErrors(array $required, $products): array
    {
        $errors = [];
        $index = 0;

        foreach ($required as $productId => $quantity) {
            $product = $products->get($productId);
            $available = $product?->stock ?? 0;

            if (! $product || $available < $quantity) {
                $name = $product?->title ?? "product #{$productId}";
                $errors["items.{$index}"] = ["Only {$available} left for '{$name}'."];
            }

            $index++;
        }

        return $errors;
    }
}
