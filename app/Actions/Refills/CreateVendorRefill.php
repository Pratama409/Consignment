<?php

namespace App\Actions\Refills;

use App\Models\User;
use App\Models\Vendor;
use App\Models\VendorItem;
use App\Models\VendorRefill;
use App\Support\OperationalNumberGenerator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CreateVendorRefill
{
    public function __construct(private OperationalNumberGenerator $numbers) {}

    /** @param list<array{item_id:string,quantity:string|int|float}> $lines */
    public function handle(Vendor $vendor, User $admin, array $lines, ?string $notes = null): VendorRefill
    {
        if (! $admin->isAdmin() || ! $vendor->is_active || $lines === []) {
            throw ValidationException::withMessages(['refill' => 'Hanya Admin dapat membuat refill untuk Vendor aktif dengan minimal satu barang.']);
        }

        return DB::transaction(function () use ($vendor, $admin, $lines, $notes): VendorRefill {
            $lockedVendor = Vendor::query()->lockForUpdate()->findOrFail($vendor->id);
            $seen = [];
            foreach ($lines as $line) {
                $quantity = (float) ($line['quantity'] ?? 0);
                $itemId = (string) ($line['item_id'] ?? '');
                if ($quantity <= 0 || isset($seen[$itemId]) || ! VendorItem::active()->where('vendor_id', $lockedVendor->id)->where('item_id', $itemId)->whereHas('item', fn ($query) => $query->where('is_active', true)->where('ownership_type', 'CONSIGNMENT'))->exists()) {
                    throw ValidationException::withMessages(['lines' => 'Barang refill harus CONSIGNMENT aktif, terhubung ke Vendor, unik, dan quantity lebih dari nol.']);
                }
                $seen[$itemId] = true;
            }
            $date = now()->format('Ymd');
            $sequence = $this->numbers->next('vendor_refill');
            $refill = VendorRefill::create([
                'refill_no' => sprintf('VRV-%s-%s-%04d', $lockedVendor->vendor_code, $date, $sequence),
                'vendor_id' => $lockedVendor->id, 'status' => 'REQUESTED', 'requested_by' => $admin->id,
                'requested_at' => now(), 'notes' => $notes,
            ]);
            foreach ($lines as $line) {
                $refill->lines()->create(['item_id' => $line['item_id'], 'requested_quantity' => $line['quantity']]);
            }

            return $refill->load('lines');
        });
    }
}
