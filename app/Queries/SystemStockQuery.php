<?php

namespace App\Queries;

use App\Models\StockBalance;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class SystemStockQuery
{
    public function build(User $viewer, array $filters): Builder
    {
        return StockBalance::query()->select('stock_balances.*')->withBucketIdentity()
            ->join('stock_buckets', 'stock_buckets.id', '=', 'stock_balances.stock_bucket_id')
            ->leftJoin('vendor_items', 'vendor_items.id', '=', 'stock_buckets.vendor_item_id')
            ->join('items', 'items.id', '=', DB::raw('COALESCE(stock_buckets.item_id, vendor_items.item_id)'))
            ->when($viewer->isVendor(), fn (Builder $query) => $query->where('vendor_items.vendor_id', $viewer->vendor_id))
            ->when($filters['vendor_id'] ?? null, fn (Builder $query, $value) => $query->where('vendor_items.vendor_id', $value))
            ->when($filters['ownership'] ?? null, fn (Builder $query, $value) => $query->where('items.ownership_type', $value))
            ->when($filters['location_id'] ?? null, fn (Builder $query, $value) => $query->where('stock_buckets.location_id', $value))
            ->when($filters['q'] ?? null, function (Builder $query, $value) {
                $term = '%'.trim($value).'%';
                $query->where(fn (Builder $nested) => $nested->where('items.item_code', 'like', $term)->orWhere('items.part_number', 'like', $term)->orWhere('items.item_name', 'like', $term));
            })
            ->orderBy('items.item_code')
            ->orderBy('stock_balances.id');
    }
}
