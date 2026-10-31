<?php

namespace App\Models;

use App\Models\Concerns\ReadsStockBucket;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OpeningBalanceLine extends Model
{
    use HasUuids, ReadsStockBucket;

    protected $fillable = ['row_number', 'stock_bucket_id', 'quantity', 'source_data'];

    protected function casts(): array
    {
        return ['quantity' => 'decimal:4', 'source_data' => 'array'];
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(OpeningBalanceBatch::class, 'opening_balance_batch_id');
    }

    public function bucket(): BelongsTo
    {
        return $this->belongsTo(StockBucket::class, 'stock_bucket_id');
    }
}
