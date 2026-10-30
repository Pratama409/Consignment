<?php

namespace App\Actions\MaterialRequests;

use App\Models\MaterialRequest;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ApproveMaterialRequest
{
    public function handle(MaterialRequest $request, User $admin, bool $approve, ?string $note = null): MaterialRequest
    {
        if (! $admin->isAdmin()) {
            throw ValidationException::withMessages(['approval' => 'Hanya Admin dapat memutuskan approval MR.']);
        }

        return DB::transaction(function () use ($request, $admin, $approve, $note): MaterialRequest {
            $locked = MaterialRequest::query()->lockForUpdate()->findOrFail($request->id);
            $approval = $locked->approvals()->where('status', 'PENDING')->lockForUpdate()->latest('revision_no')->first();
            if (! $approval || in_array($locked->status, ['COMPLETED', 'CANCELLED'], true)) {
                throw ValidationException::withMessages(['approval' => 'Tidak ada approval aktif untuk MR ini.']);
            }
            $approval->update(['status' => $approve ? 'APPROVED' : 'REJECTED', 'decided_by' => $admin->id, 'decided_at' => now(), 'decision_note' => $note]);

            return $locked->fresh(['approvals']);
        });
    }
}
