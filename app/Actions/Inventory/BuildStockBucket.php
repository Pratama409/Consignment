<?php

namespace App\Actions\Inventory;

use App\Enums\OwnershipType;
use App\Models\Item;
use App\Models\Location;
use App\Models\StockBalance;
use App\Models\StockItemLocation;
use App\Models\Vendor;
use App\Models\VendorItem;
use Illuminate\Validation\ValidationException;

class BuildStockBucket
{
    public function handle(Item $item, ?VendorItem $vendorItem, Location $location): string
    {
        if (! $item->is_active || ! $location->is_active) {
            throw ValidationException::withMessages(['stock' => 'Barang dan lokasi harus aktif.']);
        }

        if ($item->ownership_type === OwnershipType::Stock && $vendorItem === null
            && StockItemLocation::active()->where('item_id', $item->id)->where('location_id', $location->id)->exists()) {
            return "STOCK:{$item->id}:{$location->id}";
        }

        if ($item->ownership_type === OwnershipType::Consignment && $vendorItem?->is_active
            && $vendorItem->item_id === $item->id && Vendor::active()->whereKey($vendorItem->vendor_id)->exists()) {
            $bucket = "CONSIGNMENT:{$vendorItem->id}:{$location->id}";
            if ($vendorItem->location_id === $location->id || StockBalance::where('bucket_key', $bucket)->exists()) {
                return $bucket;
            }
        }

        throw ValidationException::withMessages(['stock' => 'Kepemilikan atau penempatan barang tidak sesuai atau tidak aktif.']);
    }
}
