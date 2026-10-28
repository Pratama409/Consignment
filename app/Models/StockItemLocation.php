<?php

namespace App\Models;

use App\Enums\OwnershipType;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Validation\ValidationException;

class StockItemLocation extends MasterDataModel
{
    protected $fillable = ['item_id', 'location_id', 'is_active'];

    protected static function booted(): void
    {
        parent::booted();

        static::saving(function (self $placement): void {
            if (Item::find($placement->item_id)?->ownership_type !== OwnershipType::Stock) {
                throw ValidationException::withMessages(['stock_locations' => 'Penempatan internal hanya untuk barang STOCK.']);
            }

            if ($placement->exists && $placement->isDirty(['item_id', 'location_id'])) {
                throw ValidationException::withMessages(['stock_locations' => 'Nonaktifkan penempatan lama sebelum menambahkan lokasi yang berbeda.']);
            }

            if ((! $placement->exists || ($placement->isDirty('is_active') && $placement->is_active))
                && ! Location::active()->whereKey($placement->location_id)->exists()) {
                throw ValidationException::withMessages(['stock_locations' => 'Lokasi harus terdaftar dan aktif.']);
            }
        });
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }
}
