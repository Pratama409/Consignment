<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockLotAllocation extends Model
{
    use HasUuids;

    protected $fillable = ['stock_ledger_entry_id', 'stock_lot_id', 'quantity'];

    protected function casts(): array
    {
        return ['quantity' => 'decimal:4'];
    }

    public function ledgerEntry(): BelongsTo
    {
        return $this->belongsTo(StockLedgerEntry::class, 'stock_ledger_entry_id');
    }

    public function lot(): BelongsTo
    {
        return $this->belongsTo(StockLot::class, 'stock_lot_id');
    }
}
