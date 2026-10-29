<?php

namespace App\Support;

use App\Models\Item;

class ItemPayload
{
    public static function from(Item $item): array
    {
        return [...$item->only('part_number', 'item_name', 'unit_id', 'category_id', 'minimum_stock', 'is_active'),
            'ownership_type' => $item->ownership_type->value, 'tracking_type' => $item->tracking_type->value,
            'vendor_items' => $item->vendorItems()->get()->map->only(['vendor_id', 'location_id', 'is_active'])->all(),
            'stock_locations' => $item->stockItemLocations()->get()->map->only(['location_id', 'is_active'])->all()];
    }
}
