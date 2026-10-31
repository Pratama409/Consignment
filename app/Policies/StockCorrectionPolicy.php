<?php

namespace App\Policies;

use App\Models\User;

class StockCorrectionPolicy
{
    public function viewAny(User $user): bool
    {
        return ! $user->isVendor();
    }

    public function create(User $user): bool
    {
        return $user->isAdmin() || $user->isWarehouse();
    }

    public function review(User $user): bool
    {
        return $user->isAdmin();
    }

    public function reverse(User $user): bool
    {
        return $user->isAdmin();
    }
}
