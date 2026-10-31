<?php

namespace App\Models\Concerns;

use App\Models\Item;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** For rows that hold item_id only for internal STOCK and otherwise reach the item through vendor_item_id. */
trait ResolvesDirectOrVendorItem
{
    public function stockItem(): BelongsTo
    {
        return $this->belongsTo(Item::class, 'item_id');
    }

    public function getItemAttribute(): ?Item
    {
        return $this->vendorItem?->item ?? $this->stockItem;
    }

    public function scopeWithResolvedItem(Builder $query): Builder
    {
        return $query->with(['stockItem', 'vendorItem.item']);
    }
}
