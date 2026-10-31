<?php

namespace App\Policies;

use App\Models\User;
use App\Models\VendorReconciliation;

class VendorReconciliationPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->is_active;
    }

    public function view(User $user, VendorReconciliation $ba): bool
    {
        return ! $user->isVendor() || $user->vendor_id === $ba->vendor_id;
    }

    public function create(User $user): bool
    {
        return $user->isAdmin();
    }

    public function finalize(User $user): bool
    {
        return $user->isAdmin();
    }

    public function respond(User $user, VendorReconciliation $ba): bool
    {
        return $user->isVendor() && $user->vendor_id === $ba->vendor_id;
    }
}
