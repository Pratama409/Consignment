<?php

namespace App\Actions\Reconciliation;

use App\Models\User;
use App\Models\VendorReconciliation;
use Illuminate\Validation\ValidationException;

class ResolveVendorDispute
{
    public function handle(VendorReconciliation $ba, string $note, User $admin): VendorReconciliation
    {
        if (! $admin->isAdmin() || trim($note) === '' || $ba->status->value !== 'DISPUTED') {
            throw ValidationException::withMessages(['resolution' => 'Penyelesaian selisih memerlukan Admin, BA disputed, dan catatan.']);
        }
        $ba->responses()->create(['decision' => 'RESOLVED', 'note' => trim($note), 'actor_id' => $admin->id, 'responded_at' => now()]);
        $ba->update(['status' => 'RESOLVED']);

        return $ba->fresh();
    }
}
