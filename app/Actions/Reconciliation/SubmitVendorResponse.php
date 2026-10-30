<?php

namespace App\Actions\Reconciliation;

use App\Models\User;
use App\Models\VendorReconciliation;
use App\Models\VendorReconciliationResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SubmitVendorResponse
{
    public function handle(VendorReconciliation $ba, bool $agree, string $note, User $vendorUser): VendorReconciliationResponse
    {
        if (! $vendorUser->isVendor() || $vendorUser->vendor_id !== $ba->vendor_id || (! $agree && trim($note) === '')) {
            throw ValidationException::withMessages(['response' => 'Respons Vendor tidak valid atau catatan selisih belum diisi.']);
        }

        return DB::transaction(function () use ($ba, $agree, $note, $vendorUser): VendorReconciliationResponse {
            $locked = VendorReconciliation::query()->lockForUpdate()->findOrFail($ba->id);
            if (in_array($locked->status->value, ['FINAL', 'AGREED'], true)) {
                throw ValidationException::withMessages(['response' => 'BA ini tidak lagi menerima respons Vendor.']);
            }
            $response = $locked->responses()->create(['decision' => $agree ? 'AGREE' : 'DISPUTE', 'note' => trim($note) ?: null, 'actor_id' => $vendorUser->id, 'responded_at' => now()]);
            $locked->update(['status' => $agree ? 'AGREED' : 'DISPUTED']);

            return $response;
        });
    }
}
