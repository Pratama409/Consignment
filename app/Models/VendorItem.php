<?php

namespace App\Models;

use App\Enums\OwnershipType;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
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

    public function stockBalances(): HasMany
    {
        return $this->hasMany(StockBalance::class);
    }
}
