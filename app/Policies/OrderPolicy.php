<?php

namespace App\Policies;

use App\Models\Order;
use App\Models\User;

class OrderPolicy
{
    /**
     * A user may view an order only if they own it (admins may view any).
     */
    public function view(User $user, Order $order): bool
    {
        return $user->is_admin || $order->user_id === $user->id;
    }

    /**
     * Only administrators may change an order's status.
     */
    public function updateStatus(User $user): bool
    {
        return $user->is_admin;
    }
}
