<?php

namespace App\Actions\Inventory;

use App\Enums\OwnershipType;
use App\Models\Item;
use App\Models\Location;
use App\Models\StockBucket;
use App\Models\StockItemLocation;
use App\Models\Vendor;
use App\Models\VendorItem;
use Illuminate\Validation\ValidationException;

class ResolveStockBucket
{
    public function handle(Item $item, ?VendorItem $vendorItem, Location $location): StockBucket
    {
        if (! $item->is_active || ! $location->is_active) {
            throw ValidationException::withMessages(['stock' => 'Barang dan lokasi harus aktif.']);
        }

        if ($item->ownership_type === OwnershipType::Stock && $vendorItem === null
            && StockItemLocation::active()->where('item_id', $item->id)->where('location_id', $location->id)->exists()) {
            return $this->lockedOrCreated(['item_id' => $item->id, 'location_id' => $location->id]);
        }

        if ($item->ownership_type === OwnershipType::Consignment && $vendorItem?->is_active
            && $vendorItem->item_id === $item->id && Vendor::active()->whereKey($vendorItem->vendor_id)->exists()) {
            $identity = ['vendor_item_id' => $vendorItem->id, 'location_id' => $location->id];
            $existing = StockBucket::where($identity)->lockForUpdate()->first();
            if ($existing || $vendorItem->location_id === $location->id) {
                return $existing ?? StockBucket::create($identity);
            }
        }

        throw ValidationException::withMessages(['stock' => 'Kepemilikan atau penempatan barang tidak sesuai atau tidak aktif.']);
    }

    /**
     * A locking read sees rows committed after this transaction's snapshot (REPEATABLE READ), so a concurrent
     * posting that created the bucket first is found instead of colliding on the unique index.
     *
     * @param  array{item_id: string, location_id: string}  $identity
     */
    private function lockedOrCreated(array $identity): StockBucket
    {
        return StockBucket::where($identity)->lockForUpdate()->first() ?? StockBucket::create($identity);
    }
}
