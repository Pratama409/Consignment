<?php

namespace App\Actions\Refills;

use App\Models\User;
use App\Models\VendorRefill;
use App\Models\VendorRefillRevision;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ReviewRefillRevision
{
    public function handle(VendorRefillRevision $revision, User $admin, bool $approve, ?string $note = null): VendorRefillRevision
    {
        if (! $admin->isAdmin()) {
            throw ValidationException::withMessages(['revision' => 'Hanya Admin yang dapat meninjau revisi refill.']);
        }

        return DB::transaction(function () use ($revision, $admin, $approve, $note): VendorRefillRevision {
            $locked = VendorRefillRevision::query()->lockForUpdate()->with('line')->findOrFail($revision->id);
            if ($locked->status !== 'PENDING') {
                throw ValidationException::withMessages(['revision' => 'Revisi sudah ditinjau.']);
            }
            $locked->update(['status' => $approve ? 'APPROVED' : 'REJECTED', 'reviewed_by' => $admin->id, 'reviewed_at' => now(), 'review_note' => $note]);
            $hasPending = VendorRefillRevision::whereHas('line', fn ($query) => $query->where('vendor_refill_id', $locked->line->vendor_refill_id))->where('status', 'PENDING')->exists();
            if (! $hasPending) {
                VendorRefill::whereKey($locked->line->vendor_refill_id)->update(['status' => $approve ? 'APPROVED' : 'REQUESTED']);
            }

            return $locked->fresh();
        });
    }
}
