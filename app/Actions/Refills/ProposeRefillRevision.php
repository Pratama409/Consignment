<?php

namespace App\Actions\Refills;

use App\Models\User;
use App\Models\VendorRefill;
use App\Models\VendorRefillLine;
use App\Models\VendorRefillRevision;
use App\Support\WholeQuantity;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ProposeRefillRevision
{
    public function handle(VendorRefillLine $line, User $vendorUser, string $quantity, string $reason): VendorRefillRevision
    {
        if (! $vendorUser->isVendor() || ! WholeQuantity::isPositive($quantity) || trim($reason) === '') {
            throw ValidationException::withMessages(['revision' => 'Revisi Vendor memerlukan quantity satuan bulat positif dan alasan.']);
        }

        return DB::transaction(function () use ($line, $vendorUser, $quantity, $reason): VendorRefillRevision {
            $identity = VendorRefillLine::findOrFail($line->id);
            $refill = VendorRefill::query()->lockForUpdate()->findOrFail($identity->vendor_refill_id);
            $locked = VendorRefillLine::query()->lockForUpdate()->findOrFail($line->id);
            if ($refill->vendor_id !== $vendorUser->vendor_id || in_array($refill->status, ['COMPLETED', 'CLOSED_PARTIAL', 'CANCELLED'], true)) {
                throw ValidationException::withMessages(['revision' => 'Vendor tidak berhak merevisi refill ini.']);
            }
            if ($locked->revisions()->where('status', 'PENDING')->exists()) {
                throw ValidationException::withMessages(['revision' => 'Masih ada revisi yang menunggu keputusan Admin.']);
            }
            $revision = $locked->revisions()->create(['proposed_quantity' => $quantity, 'reason' => trim($reason), 'status' => 'PENDING', 'proposed_by' => $vendorUser->id]);
            $refill->update(['status' => 'REVISION_PENDING']);

            return $revision->load('line');
        });
    }
}
