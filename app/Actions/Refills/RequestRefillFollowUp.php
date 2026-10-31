<?php

namespace App\Actions\Refills;

use App\Actions\Audit\WriteAuditLog;
use App\Models\User;
use App\Models\VendorRefill;
use App\Models\VendorRefillFollowUp;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RequestRefillFollowUp
{
    public function __construct(private WriteAuditLog $audit) {}

    /** Returns the open follow-up if one is already waiting for the Vendor, so repeated clicks do not stack up. */
    public function handle(VendorRefill $refill, User $user, ?string $note): VendorRefillFollowUp
    {
        if ((! $user->isAdmin() && ! $user->isWarehouse()) || ! $user->is_active) {
            throw ValidationException::withMessages(['follow_up' => 'Hanya Admin atau Warehouse aktif yang dapat meminta tindak lanjut.']);
        }

        return DB::transaction(function () use ($refill, $user, $note): VendorRefillFollowUp {
            $locked = VendorRefill::query()->lockForUpdate()->findOrFail($refill->id);
            if ($locked->status !== 'PARTIAL') {
                throw ValidationException::withMessages(['follow_up' => 'Tindak lanjut hanya untuk refill yang baru diterima sebagian.']);
            }
            $open = $locked->followUps()->whereNull('acknowledged_at')->first();
            if ($open !== null) {
                return $open;
            }
            $note = trim((string) $note);
            $followUp = $locked->followUps()->create(['requested_by' => $user->id, 'note' => $note === '' ? null : $note]);
            $this->audit->handle($user, 'vendor_refill.follow_up_requested', $locked, [], $followUp->toArray(), $followUp->note);

            return $followUp;
        });
    }
}
