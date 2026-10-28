<?php

namespace App\Models;

use App\Enums\OwnershipType;
use App\Enums\TrackingType;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Validation\ValidationException;

class Item extends MasterDataModel
{
    protected $fillable = [
        'item_code', 'part_number', 'item_name', 'ownership_type', 'tracking_type',
        'unit_id', 'minimum_stock', 'is_active',
    ];

    protected static function booted(): void
    {
        parent::booted();

        static::updating(function (self $item): void {
            foreach (['ownership_type', 'item_code'] as $column) {
                if ($item->isDirty($column)) {
                    throw ValidationException::withMessages([$column => 'Jenis kepemilikan dan kode internal dikunci setelah barang dibuat.']);
                }
            }
        });
    }

    protected function codeColumn(): ?string
    {
        return 'item_code';
    }

    protected function casts(): array
    {
        return [
            ...parent::casts(),
            'ownership_type' => OwnershipType::class,
            'tracking_type' => TrackingType::class,
            'minimum_stock' => 'decimal:4',
        ];
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
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
