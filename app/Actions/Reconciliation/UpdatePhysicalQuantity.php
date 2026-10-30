<?php

namespace App\Actions\Reconciliation;

use App\Models\User;
use App\Models\VendorReconciliationLine;
use Illuminate\Validation\ValidationException;

class UpdatePhysicalQuantity
{
    public function handle(VendorReconciliationLine $line, string $quantity, User $admin): VendorReconciliationLine
    {
        if (! $admin->isAdmin() || ! preg_match('/^(0|[1-9][0-9]*)$/', $quantity) || $line->reconciliation->status->value !== 'DRAFT') {
            throw ValidationException::withMessages(['physical_quantity' => 'Quantity fisik hanya dapat diisi Admin pada BA draft dalam satuan bulat nonnegatif.']);
        }
        $line->update(['physical_quantity' => $quantity, 'variance_quantity' => number_format((int) $quantity - (float) $line->system_closing_quantity, 4, '.', '')]);

        return $line->fresh();
    }
}
