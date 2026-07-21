<?php

namespace App\Policies;

use App\Models\User;

class ProductPolicy
{
    /**
     * Any authenticated user can browse the catalogue.
     */
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user): bool
    {
        return true;
    }

    /**
     * Only administrators may manage the catalogue.
     */
    public function create(User $user): bool
    {
        return $user->is_admin;
    }

    public function update(User $user): bool
    {
        return $user->is_admin;
    }

    public function delete(User $user): bool
    {
        return $user->is_admin;
    }
}
