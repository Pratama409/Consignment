<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Validation\ValidationException;

class Location extends MasterDataModel
{
    public const UNASSIGNED = 'UNASSIGNED';

    protected $fillable = ['location_code', 'location_name', 'is_active'];

    protected static function booted(): void
    {
        parent::booted();

        static::updating(function (self $location): void {
            if ($location->getRawOriginal('location_code') === self::UNASSIGNED
                && ($location->isDirty('location_code') || ! $location->is_active)) {
                throw ValidationException::withMessages(['location_code' => 'Lokasi sistem UNASSIGNED harus tetap tersedia dan aktif.']);
            }

            if ($location->isDirty('is_active') && ! $location->is_active
                && ($location->vendorItems()->active()->exists() || $location->stockItemLocations()->active()->exists())) {
                throw ValidationException::withMessages(['is_active' => 'Lokasi masih digunakan penempatan aktif.']);
            }
        });
    }

    protected function codeColumn(): ?string
    {
        return 'location_code';
    }

    public function vendorItems(): HasMany
    {
        return $this->hasMany(VendorItem::class);
    }

    public function stockItemLocations(): HasMany
    {
        return $this->hasMany(StockItemLocation::class);
    }
}
