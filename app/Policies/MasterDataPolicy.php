<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class MasterDataPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Model $record): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return $user->role === UserRole::Admin;
    }

    public function update(User $user, Model $record): bool
    {
        return $user->role === UserRole::Admin;
    }
}
