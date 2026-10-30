<?php

namespace App\Actions\Reconciliation;

use App\Models\User;
use App\Models\VendorReconciliation;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class FinalizeVendorReconciliation
{
    public function handle(VendorReconciliation $ba, User $admin): VendorReconciliation
    {
        if (! $admin->isAdmin()) {
            throw ValidationException::withMessages(['finalize' => 'Hanya Admin dapat memfinalisasi BA.']);
        }

        return DB::transaction(function () use ($ba, $admin): VendorReconciliation {
            $locked = VendorReconciliation::query()->lockForUpdate()->findOrFail($ba->id);
            if ($locked->status->value === 'FINAL') {
                return $locked;
            }
            if (! in_array($locked->status->value, ['AGREED', 'RESOLVED'], true)) {
                throw ValidationException::withMessages(['finalize' => 'BA harus disetujui atau diselesaikan sebelum final.']);
            }
            $lines = $locked->lines()->orderBy('vendor_item_id')->orderBy('location_id')->get()->map->only(['vendor_item_id', 'location_id', 'opening_quantity', 'in_quantity', 'out_quantity', 'adjustment_quantity', 'system_closing_quantity', 'physical_quantity', 'variance_quantity'])->all();
            $checksum = hash('sha256', json_encode(['id' => $locked->id, 'version' => $locked->version, 'lines' => $lines], JSON_UNESCAPED_SLASHES));
            $locked->update(['status' => 'FINAL', 'checksum' => $checksum, 'finalized_by' => $admin->id, 'finalized_at' => now()]);

            return $locked->fresh();
        });
    }
}
