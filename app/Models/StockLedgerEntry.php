<?php

namespace App\Models;

use App\Models\Concerns\ReadsStockBucket;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class StockLedgerEntry extends Model
{
    use HasUuids, ReadsStockBucket;

    protected $fillable = ['stock_transaction_id', 'stock_bucket_id', 'quantity', 'entry_sequence', 'physical_occurred_at'];

    protected function casts(): array
    {
        return ['quantity' => 'decimal:4', 'entry_sequence' => 'integer', 'physical_occurred_at' => 'immutable_datetime'];
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(StockTransaction::class, 'stock_transaction_id');
    }

    public function bucket(): BelongsTo
    {
        return $this->belongsTo(StockBucket::class, 'stock_bucket_id');
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
