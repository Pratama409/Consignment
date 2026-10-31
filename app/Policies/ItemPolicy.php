<?php

namespace App\Policies;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class ItemPolicy extends MasterDataPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Model $record): bool
    {
        return ! $user->isVendor() || $record->vendorItems()->where('vendor_id', $user->vendor_id)->active()->exists();
    }
}
