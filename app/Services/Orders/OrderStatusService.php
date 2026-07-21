<?php

namespace App\Services\Orders;

use App\Enums\OrderStatus;
use App\Events\OrderStatusChanged;
use App\Exceptions\InvalidStatusTransitionException;
use App\Models\Order;
use App\Models\OrderStatusHistory;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class OrderStatusService
{
    /**
     * Apply a status change to an order.
     *
     * Returns the recorded history row, or null when the status is unchanged
     * (a no-op that writes no history and sends no notification).
     *
     * @throws InvalidStatusTransitionException
     */
    public function change(Order $order, OrderStatus $to, User $admin): ?OrderStatusHistory
    {
        $from = $order->status;

        // Submitting the current status again is a no-op.
        if ($from === $to) {
            return null;
        }

        if (! $from->canTransitionTo($to)) {
            throw new InvalidStatusTransitionException($from, $to);
        }

        // The status update and its history row are written together, so the
        // audit trail can never diverge from the order's actual status.
        $history = DB::transaction(function () use ($order, $from, $to, $admin) {
            $order->update(['status' => $to]);

            return $order->statusHistories()->create([
                'from_status' => $from,
                'to_status' => $to,
                'changed_by' => $admin->id,
            ]);
        });

        // Dispatched after commit; the queued listener notifies the owner off
        // the request cycle.
        OrderStatusChanged::dispatch($history);

        return $history;
    }
}
