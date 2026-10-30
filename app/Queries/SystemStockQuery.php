<?php

namespace App\Queries;

use App\Models\StockBalance;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

class SystemStockQuery
{
    public function build(User $viewer, array $filters): Builder
    {
        return StockBalance::query()->with(['item.unit', 'vendorItem.vendor', 'location'])
            ->when($viewer->isVendor(), fn (Builder $query) => $query->whereHas('vendorItem', fn ($relation) => $relation->where('vendor_id', $viewer->vendor_id)))
            ->when($filters['vendor_id'] ?? null, fn (Builder $query, $value) => $query->whereHas('vendorItem', fn ($relation) => $relation->where('vendor_id', $value)))
            ->when($filters['ownership'] ?? null, fn (Builder $query, $value) => $query->whereHas('item', fn ($item) => $item->where('ownership_type', $value)))
            ->when($filters['location_id'] ?? null, fn (Builder $query, $value) => $query->where('location_id', $value))
            ->when($filters['q'] ?? null, function (Builder $query, $value) {
                $term = '%'.trim($value).'%';
                $query->whereHas('item', fn ($item) => $item->where('item_code', 'like', $term)->orWhere('part_number', 'like', $term)->orWhere('item_name', 'like', $term));
            })
            ->orderBy('bucket_key');
    }
}
