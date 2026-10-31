<?php

namespace App\Actions\Procurement;

use App\Models\ProcurementRequirement;
use App\Support\WholeQuantity;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SaveProcurementReference
{
    public function handle(ProcurementRequirement $requirement, string $stage, array $lines): ProcurementRequirement
    {
        return DB::transaction(function () use ($requirement, $stage, $lines) {
            $locked = ProcurementRequirement::lockForUpdate()->findOrFail($requirement->id);
            $allowed = ['RESERVATION' => ['RESERVATION_PENDING', 'PR_PENDING'], 'PR' => ['PR_PENDING', 'VALIDATION_PENDING', 'REQUIRES_REVIEW'], 'PO' => ['PO_PENDING'], 'GRGI' => ['PO_PENDING']];
            if (! in_array($locked->status, $allowed[$stage] ?? [], true)) {
                throw ValidationException::withMessages(['stage' => "Tahap {$stage} tidak dapat diproses dari status {$locked->status}."]);
            }
            if ($stage === 'PR' && ! $locked->documentLines()->where(['stage' => 'RESERVATION', 'is_active' => true])->exists()) {
                throw ValidationException::withMessages(['stage' => 'PR hanya dapat diimpor setelah Reservation aktif tersedia.']);
            }
            if ($stage === 'PO' && $locked->status !== 'PO_PENDING') {
                throw ValidationException::withMessages(['stage' => 'PO hanya dapat diimpor setelah PR divalidasi.']);
            }
            if ($stage === 'GRGI' && ! $locked->documentLines()->where(['stage' => 'PO', 'is_active' => true])->exists()) {
                throw ValidationException::withMessages(['stage' => 'GR/GI hanya dapat diimpor setelah PO aktif tersedia.']);
            }
            $total = 0;
            foreach ($lines as $line) {
                if (! WholeQuantity::isPositive($line['quantity'] ?? null) || trim($line['document_no'] ?? '') === '') {
                    throw ValidationException::withMessages(['reference' => 'Nomor dan quantity bulat wajib diisi.']);
                }$total += (int) $line['quantity'];
            }
            if ($total > $locked->requiredQuantity()) {
                throw ValidationException::withMessages(['quantity' => 'Total referensi melebihi Data Out.']);
            }
            $previousStage = ['PO' => 'PR', 'GRGI' => 'PO'][$stage] ?? null;
            if ($previousStage && $total > (int) $locked->documentLines()->where(['stage' => $previousStage, 'is_active' => true])->sum('covered_quantity')) {
                throw ValidationException::withMessages(['quantity' => "Total {$stage} melebihi quantity {$previousStage} aktif."]);
            }
            $version = (int) $locked->documentLines()->where('stage', $stage)->max('version') + 1;
            $locked->documentLines()->where('stage', $stage)->update(['is_active' => false]);
            foreach ($lines as $i => $line) {
                $locked->documentLines()->create(['stage' => $stage, 'version' => $version, 'line_no' => $i + 1, 'document_no' => $line['document_no'], 'covered_quantity' => $line['quantity'], 'is_active' => true]);
            }$locked->update(['status' => $stage === 'RESERVATION' ? 'PR_PENDING' : ($stage === 'PR' ? 'VALIDATION_PENDING' : ($stage === 'GRGI' && $total === $locked->requiredQuantity() ? 'COMPLETE' : $locked->status))]);

            return $locked->fresh('documentLines');
        });
    }
}
