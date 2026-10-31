<?php

namespace App\Actions\Refills;

use App\Actions\Audit\WriteAuditLog;
use App\Models\User;
use App\Models\VendorRefill;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AcknowledgeRefillFollowUp
{
    public function __construct(private WriteAuditLog $audit) {}

    public function handle(VendorRefill $refill, User $vendorUser): int
    {
        if (! $vendorUser->isVendor() || ! $vendorUser->is_active || $vendorUser->vendor_id !== $refill->vendor_id) {
            throw ValidationException::withMessages(['follow_up' => 'Hanya Vendor pemilik refill yang dapat menandai tindak lanjut dibaca.']);
        }

        return DB::transaction(function () use ($refill, $vendorUser): int {
            $open = VendorRefill::query()->lockForUpdate()->findOrFail($refill->id)->followUps()->whereNull('acknowledged_at')->lockForUpdate()->get();
            foreach ($open as $followUp) {
                $followUp->update(['acknowledged_by' => $vendorUser->id, 'acknowledged_at' => now()]);
                $this->audit->handle($vendorUser, 'vendor_refill.follow_up_acknowledged', $refill, [], $followUp->fresh()->toArray());
            }

            return $open->count();
        });
    }
}
