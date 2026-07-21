<?php

namespace App\Events;

use App\Models\OrderStatusHistory;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class OrderStatusChanged
{
    use Dispatchable, SerializesModels;

    /**
     * Carries the history record for the change, which pins the exact
     * transition and lets the listener de-duplicate on retries.
     */
    public function __construct(public readonly OrderStatusHistory $history) {}
}
