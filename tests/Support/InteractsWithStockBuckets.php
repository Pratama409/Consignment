<?php

namespace Tests\Support;

use App\Models\Item;
use App\Models\Location;
use App\Models\StockBucket;
use App\Models\VendorItem;

/** Raw bucket creation for tests that must bypass ResolveStockBucket's business validation. */
trait InteractsWithStockBuckets
{
    protected function bucketFor(Item $item, ?VendorItem $vendorItem, Location $location): StockBucket
    {
        return $vendorItem !== null
            ? StockBucket::firstOrCreate(['vendor_item_id' => $vendorItem->id, 'location_id' => $location->id])
            : StockBucket::firstOrCreate(['item_id' => $item->id, 'location_id' => $location->id]);
    }
}
