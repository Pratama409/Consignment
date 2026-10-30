<?php

namespace App\Actions\Refills;

use App\Models\User;
use App\Models\VendorRefill;
use App\Models\VendorRefillLine;
use App\Models\VendorRefillRevision;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ProposeRefillRevision
{
    public function handle(VendorRefillLine $line, User $vendorUser, string $quantity, string $reason): VendorRefillRevision
    {
        if (! $vendorUser->isVendor() || (float) $quantity <= 0 || trim($reason) === '') {
            throw ValidationException::withMessages(['revision' => 'Revisi Vendor memerlukan quantity positif dan alasan.']);
        }

        return DB::transaction(function () use ($line, $vendorUser, $quantity, $reason): VendorRefillRevision {
            $locked = VendorRefillLine::query()->lockForUpdate()->with('refill')->findOrFail($line->id);
            if ($locked->refill->vendor_id !== $vendorUser->vendor_id || in_array($locked->refill->status, ['COMPLETED', 'CLOSED_PARTIAL', 'CANCELLED'], true)) {
                throw ValidationException::withMessages(['revision' => 'Vendor tidak berhak merevisi refill ini.']);
            }
            if ($locked->revisions()->where('status', 'PENDING')->exists()) {
                throw ValidationException::withMessages(['revision' => 'Masih ada revisi yang menunggu keputusan Admin.']);
            }
            $revision = $locked->revisions()->create(['proposed_quantity' => $quantity, 'reason' => trim($reason), 'status' => 'PENDING', 'proposed_by' => $vendorUser->id]);
            VendorRefill::whereKey($locked->vendor_refill_id)->update(['status' => 'REVISION_PENDING']);

            return $revision->load('line');
        });
    }
}
