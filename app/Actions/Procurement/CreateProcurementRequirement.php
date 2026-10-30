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

return ProcurementRequirement::firstOrCreate(['material_issue_line_id' => $line->id], ['row_key' => (string) Str::uuid(), 'vendor_item_id' => $line->vendor_item_id, 'item_id' => $line->requestLine->item_id, 'location_id' => $line->location_id, 'required_quantity' => $line->issued_quantity, 'status' => 'RESERVATION_PENDING']);
    }
}
