<?php

namespace App\Actions\Refills;

use App\Models\User;
use App\Models\VendorRefill;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CloseVendorRefill
{
    public function handle(VendorRefill $refill, User $admin, string $reason): VendorRefill
    {
        if (! $admin->isAdmin() || trim($reason) === '') {
            throw ValidationException::withMessages(['close_reason' => 'Admin wajib mengisi alasan penutupan sisa refill.']);
        }

        return DB::transaction(function () use ($refill, $admin, $reason): VendorRefill {
            $locked = VendorRefill::query()->lockForUpdate()->findOrFail($refill->id);
            if (in_array($locked->status, ['COMPLETED', 'CLOSED_PARTIAL', 'CANCELLED'], true)) {
                throw ValidationException::withMessages(['refill' => 'Refill sudah selesai atau ditutup.']);
            }
            $locked->update(['status' => 'CLOSED_PARTIAL', 'closed_by' => $admin->id, 'closed_at' => now(), 'close_reason' => trim($reason)]);

            return $locked->fresh();
        });
    }
}
