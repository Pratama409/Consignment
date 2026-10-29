<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class StockLedgerEntry extends Model
{
    use HasUuids;

    protected $fillable = ['stock_transaction_id', 'item_id', 'vendor_item_id', 'vendor_id', 'location_id', 'bucket_key', 'quantity', 'entry_sequence', 'physical_occurred_at'];

    protected function casts(): array
    {
        return ['quantity' => 'decimal:4', 'entry_sequence' => 'integer', 'physical_occurred_at' => 'immutable_datetime'];
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(StockTransaction::class, 'stock_transaction_id');
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    public function vendorItem(): BelongsTo
    {
        return $this->belongsTo(VendorItem::class);
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function lot(): HasOne
    {
        return $this->hasOne(StockLot::class);
    }

    public function lotAllocations(): HasMany
    {
        return $this->hasMany(StockLotAllocation::class);
    }
}
