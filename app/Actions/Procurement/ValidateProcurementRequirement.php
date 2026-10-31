<?php

namespace App\Actions\Procurement;

use App\Models\ProcurementRequirement;
use App\Models\User;
use Illuminate\Validation\ValidationException;

class ValidateProcurementRequirement
{
    public function handle(ProcurementRequirement $r, string $decision, ?string $note, User $admin)
    {
        if (! $admin->isAdmin() || (! in_array($decision, ['VALID', 'REVISE', 'REJECT'])) || ($decision !== 'VALID' && trim($note ?? '') === '')) {
            throw ValidationException::withMessages(['validation' => 'Keputusan tidak valid.']);
        }
        if ($r->status !== 'VALIDATION_PENDING' || ! $r->documentLines()->where(['stage' => 'PR', 'is_active' => true])->exists()) {
            throw ValidationException::withMessages(['validation' => 'Hanya PR aktif yang menunggu validasi dapat diproses.']);
        }
        $r->validations()->create(['decision' => $decision, 'note' => $note, 'validated_by' => $admin->id, 'validated_at' => now()]);
        $r->update(['status' => $decision === 'VALID' ? 'PO_PENDING' : ($decision === 'REVISE' ? 'PR_PENDING' : 'REQUIRES_REVIEW')]);

        return $r->fresh();
    }
}
