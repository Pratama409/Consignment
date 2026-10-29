<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StockLot extends Model
{
    use HasUuids;

    protected $fillable = ['stock_ledger_entry_id', 'item_id', 'vendor_item_id', 'location_id', 'bucket_key', 'received_at', 'original_quantity', 'remaining_quantity'];

    protected function casts(): array
    {
        return ['received_at' => 'immutable_datetime', 'original_quantity' => 'decimal:4', 'remaining_quantity' => 'decimal:4'];
    }

    public function ledgerEntry(): BelongsTo
    {
        return $this->belongsTo(StockLedgerEntry::class, 'stock_ledger_entry_id');
    }

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

    public function allocations(): HasMany
    {
        return $this->hasMany(StockLotAllocation::class);
    }
}
