<?php

namespace App\Services\Orders;

use App\Models\Order;

class OrderPlacementResult
{
    public function __construct(
        public readonly Order $order,
        public readonly bool $replayed,
    ) {}
}
