<?php

namespace App\Models;

use App\Enums\OwnershipType;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class VendorItem extends MasterDataModel
{
    protected $fillable = ['item_id', 'vendor_id', 'location_id', 'is_active'];

    protected static function booted(): void
    {
        parent::booted();

        static::saving(function (self $relation): void {
            $item = Item::find($relation->item_id);

            if ($item?->ownership_type !== OwnershipType::Consignment) {
                throw ValidationException::withMessages(['vendor_items' => 'Hanya barang CONSIGNMENT yang boleh mempunyai Vendor.']);
            }

            if ($relation->exists && $relation->isDirty(['item_id', 'vendor_id'])) {
                throw ValidationException::withMessages(['vendor_items' => 'Identitas pasangan barang–Vendor tidak boleh diganti.']);
            }

            if (! $relation->exists || $relation->isDirty('location_id')
                || ($relation->isDirty('is_active') && $relation->is_active)) {
                if (! Vendor::active()->whereKey($relation->vendor_id)->exists()) {
                    throw ValidationException::withMessages(['vendor_items' => 'Vendor harus terdaftar dan aktif.']);
                }

                if (! Location::active()->whereKey($relation->location_id)->exists()) {
                    throw ValidationException::withMessages(['vendor_items' => 'Lokasi harus terdaftar dan aktif.']);
                }
            }

            if ($relation->exists && $relation->isDirty('is_active') && ! $relation->is_active
                && ! $item->vendorItems()->active()->whereKeyNot($relation->id)->exists()) {
                throw ValidationException::withMessages(['vendor_items' => 'Barang CONSIGNMENT harus mempunyai minimal satu hubungan Vendor aktif.']);
            }
        });
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function stockBuckets(): HasMany
    {
        return $this->hasMany(StockBucket::class);
    }

    /**
     * The default location plus every location where this pair still holds stock. When stockBuckets is
     * eager-loaded the caller has already restricted it to buckets with a positive balance.
     *
     * @return Collection<int, string>
     */
    public function usableLocationIds(): Collection
    {
        $buckets = $this->relationLoaded('stockBuckets')
            ? $this->stockBuckets
            : $this->stockBuckets()->whereHas('balance', fn ($balance) => $balance->where('quantity', '>', 0))->get();

        return collect([$this->location_id])->merge($buckets->pluck('location_id'))->unique()->values();
    }

    public function stockBalances(): HasManyThrough
    {
        return $this->hasManyThrough(StockBalance::class, StockBucket::class, 'vendor_item_id', 'stock_bucket_id');
    }
}
