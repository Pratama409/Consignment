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
            $total = 0;
            foreach ($lines as $line) {
                if (! WholeQuantity::isPositive($line['quantity'] ?? null) || trim($line['document_no'] ?? '') === '') {
                    throw ValidationException::withMessages(['reference' => 'Nomor dan quantity bulat wajib diisi.']);
                }$total += (int) $line['quantity'];
            }if ($total > (int) $locked->required_quantity) {
                throw ValidationException::withMessages(['quantity' => 'Total referensi melebihi Data Out.']);
            }$version = (int) $locked->documentLines()->where('stage', $stage)->max('version') + 1;
            $locked->documentLines()->where('stage', $stage)->update(['is_active' => false]);
            foreach ($lines as $i => $line) {
                $locked->documentLines()->create(['stage' => $stage, 'version' => $version, 'line_no' => $i + 1, 'document_no' => $line['document_no'], 'covered_quantity' => $line['quantity'], 'is_active' => true]);
            }$locked->update(['status' => $stage === 'PR' ? 'VALIDATION_PENDING' : ($stage === 'PO' && $total === (int) $locked->required_quantity ? 'COMPLETE' : $locked->status)]);

            return $locked->fresh('documentLines');
        });
    }
}
