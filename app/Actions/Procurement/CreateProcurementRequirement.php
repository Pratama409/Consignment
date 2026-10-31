<?php

namespace App\Actions\Procurement;

use App\Enums\OwnershipType;
use App\Models\MaterialIssueLine;
use App\Models\ProcurementRequirement;
use Illuminate\Support\Str;

class CreateProcurementRequirement
{
    public function handle(MaterialIssueLine $line): ?ProcurementRequirement
    {
        $line->loadMissing('requestLine.item');
        if ($line->requestLine->item->ownership_type !== OwnershipType::Consignment) {
            return null;
        }

        return ProcurementRequirement::firstOrCreate(['material_issue_line_id' => $line->id], ['row_key' => (string) Str::uuid(), 'status' => 'RESERVATION_PENDING']);
    }
}
