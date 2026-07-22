<?php

namespace App\Services\Orders;

class OrderRequestFingerprint
{
    /**
     * @param  array<int, array{product_id: int, quantity: int}>  $items
     */
    public function hash(array $items): string
    {
        $quantities = [];

        foreach ($items as $item) {
            $productId = (int) $item['product_id'];
            $quantities[$productId] = ($quantities[$productId] ?? 0) + (int) $item['quantity'];
        }

        ksort($quantities, SORT_NUMERIC);

        $canonicalItems = [];
        foreach ($quantities as $productId => $quantity) {
            $canonicalItems[] = [
                'product_id' => (int) $productId,
                'quantity' => $quantity,
            ];
        }

        return hash('sha256', json_encode($canonicalItems, JSON_THROW_ON_ERROR));
    }
}
