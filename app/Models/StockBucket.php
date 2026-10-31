<?php

namespace App\Models;

use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/** A stock position: an internal item, or a Vendor's item, at one location. */
class StockBucket extends Model
{
    use HasUuids;

    protected $fillable = ['item_id', 'vendor_item_id', 'location_id'];

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    public function vendorItem(): BelongsTo
    {
        return $this->belongsTo(VendorItem::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function balance(): HasOne
    {
        return $this->hasOne(StockBalance::class);
    }

    public function resolvedItem(): ?Item
    {
        return $this->vendorItem?->item ?? $this->item;
    }

    public function vendorId(): ?string
    {
        return $this->vendorItem?->vendor_id;
    }

    /** Constrain the bucket's item whether it is held directly or through a Vendor pair. */
    public function scopeWhereItem(Builder $query, Closure $constraint): Builder
    {
        return $query->where(fn (Builder $inner) => $inner->whereHas('item', $constraint)->orWhereHas('vendorItem.item', $constraint));
    }
}
