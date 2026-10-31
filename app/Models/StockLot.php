<?php

namespace App\Models;

use App\Models\Concerns\ReadsStockBucket;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StockLot extends Model
{
    use HasUuids, ReadsStockBucket;

    protected $fillable = ['stock_ledger_entry_id', 'stock_bucket_id', 'received_at', 'original_quantity', 'remaining_quantity'];

    protected function casts(): array
    {
        return ['received_at' => 'immutable_datetime', 'original_quantity' => 'decimal:4', 'remaining_quantity' => 'decimal:4'];
    }

    public function ledgerEntry(): BelongsTo
    {
        return $this->belongsTo(StockLedgerEntry::class, 'stock_ledger_entry_id');
    }

    public function bucket(): BelongsTo
    {
        return $this->belongsTo(StockBucket::class, 'stock_bucket_id');
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(StockLotAllocation::class);
    }
}
