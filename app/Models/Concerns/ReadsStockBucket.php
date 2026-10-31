<?php

namespace App\Models\Concerns;

use App\Models\Item;
use App\Models\Location;
use App\Models\StockBucket;
use App\Models\Vendor;
use App\Models\VendorItem;
use Illuminate\Database\Eloquent\Builder;

/** Read-only item, Vendor and location views for rows that reference a stock bucket. */
trait ReadsStockBucket
{
    public function getItemAttribute(): ?Item
    {
        return $this->bucket->resolvedItem();
    }

    public function getVendorItemAttribute(): ?VendorItem
    {
        return $this->bucket->vendorItem;
    }

    public function getVendorAttribute(): ?Vendor
    {
        return $this->bucket->vendorItem?->vendor;
    }

    public function getLocationAttribute(): Location
    {
        return $this->bucket->location;
    }

    public function scopeWithBucketIdentity(Builder $query): Builder
    {
        return $query->with(array_map(fn (string $relation) => "bucket.{$relation}", StockBucket::IDENTITY));
    }
}
