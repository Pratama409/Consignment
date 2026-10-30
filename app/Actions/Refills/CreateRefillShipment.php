<?php

namespace App\Actions\Refills;

use App\Models\User;
use App\Models\VendorRefill;
use App\Models\VendorRefillLine;
use App\Models\VendorRefillShipment;
use App\Models\VendorRefillShipmentLine;
use App\Support\WholeQuantity;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CreateRefillShipment
{
    /** @param list<array{refill_line_id:string,quantity:string|int|float}> $lines */
    public function handle(VendorRefill $refill, User $vendorUser, string $documentNo, CarbonImmutable $shippedAt, array $lines, ?string $expedition = null): VendorRefillShipment
    {
        if (! $vendorUser->isVendor() || trim($documentNo) === '' || $lines === []) {
            throw ValidationException::withMessages(['shipment' => 'Data pengiriman Vendor belum lengkap.']);
        }

        return DB::transaction(function () use ($refill, $vendorUser, $documentNo, $shippedAt, $lines, $expedition): VendorRefillShipment {
            $locked = VendorRefill::query()->lockForUpdate()->findOrFail($refill->id);
            if ($locked->vendor_id !== $vendorUser->vendor_id || $locked->status === 'REVISION_PENDING' || in_array($locked->status, ['COMPLETED', 'CLOSED_PARTIAL', 'CANCELLED'], true)) {
                throw ValidationException::withMessages(['shipment' => 'Refill belum dapat dikirim oleh Vendor ini.']);
            }
            $seen = [];
            foreach ($lines as $input) {
                $line = VendorRefillLine::where('vendor_refill_id', $locked->id)->whereKey($input['refill_line_id'] ?? '')->lockForUpdate()->first();
                $alreadyShipped = $line ? VendorRefillShipmentLine::where('vendor_refill_line_id', $line->id)->sum('shipped_quantity') : 0;
                if (! $line || isset($seen[$line->id]) || ! WholeQuantity::isPositive($input['quantity'] ?? null) || (float) $alreadyShipped + (float) $input['quantity'] > (float) $line->requested_quantity) {
                    throw ValidationException::withMessages(['shipment' => 'Baris pengiriman tidak valid atau melebihi permintaan.']);
                }
                $seen[$line->id] = true;
            }
            $shipment = $locked->shipments()->create(['vendor_document_no' => trim($documentNo), 'expedition_name' => $expedition, 'shipped_at' => $shippedAt->utc(), 'status' => 'DISPATCHED', 'created_by' => $vendorUser->id]);
            foreach ($lines as $input) {
                $shipment->lines()->create(['vendor_refill_line_id' => $input['refill_line_id'], 'shipped_quantity' => $input['quantity']]);
            }
            $locked->update(['status' => 'SHIPPING']);

            return $shipment->load('lines');
        });
    }
}
