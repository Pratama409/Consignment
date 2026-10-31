<?php

namespace App\Policies;

use App\Models\User;

class MonitoringPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->is_active;
    }

    public function export(User $user): bool
    {
        return $user->is_active;
    }
}
