<?php

namespace App\Services\Orders;

use App\Enums\OrderStatus;
use App\Events\OrderStatusChanged;
use App\Exceptions\InvalidStatusTransitionException;
use App\Models\Order;
use App\Models\OrderStatusHistory;
use App\Models\Product;
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
        // The status update, any restock, and the history row are written
        // together, so the audit trail can never diverge from the order's
        // actual status and stock can never be silently lost or duplicated.
        $history = DB::transaction(function () use ($order, $to, $admin) {
            $lockedOrder = Order::query()
                ->whereKey($order->getKey())
                ->lockForUpdate()
                ->firstOrFail();
            $from = $lockedOrder->status;

            // Submitting the latest committed status again is a no-op.
            if ($from === $to) {
                return null;
            }

            if (! $from->canTransitionTo($to)) {
                throw new InvalidStatusTransitionException($from, $to);
            }

            if ($to === OrderStatus::Cancelled) {
                $this->restockItems($lockedOrder);
            }

            $lockedOrder->update(['status' => $to]);

            return $lockedOrder->statusHistories()->create([
                'from_status' => $from,
                'to_status' => $to,
                'changed_by' => $admin->id,
            ]);
        }, attempts: 3);

        if ($history === null) {
            return null;
        }

        // Dispatched after commit; the queued listener notifies the owner off
        // the request cycle.
        OrderStatusChanged::dispatch($history);

        return $history;
    }

    /**
     * Return every item's quantity to product stock. Runs inside the caller's
     * transaction. Rows are locked in a stable id order for the same reason
     * OrderService locks them when decrementing: it prevents a lost update
     * against a concurrent order for the same product. (order_items has a
     * unique(order_id, product_id) constraint, so each product appears at
     * most once per order - no need to sum quantities across lines.)
     */
    private function restockItems(Order $order): void
    {
        $items = $order->items()->get(['product_id', 'quantity']);

        Product::query()
            ->whereIn('id', $items->pluck('product_id'))
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->each(function (Product $product) use ($items) {
                $quantity = (int) $items->firstWhere('product_id', $product->id)->quantity;
                $product->increment('stock', $quantity);
            });
    }
}
