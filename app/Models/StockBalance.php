<?php

namespace App\Models;

use App\Models\Concerns\ReadsStockBucket;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockBalance extends Model
{
    use HasUuids, ReadsStockBucket;

    protected $fillable = ['stock_bucket_id', 'quantity', 'version'];

    protected $attributes = ['quantity' => '0.0000', 'version' => 0];

    protected function casts(): array
    {
        return ['quantity' => 'decimal:4', 'version' => 'integer'];
    }

    public function bucket(): BelongsTo
    {
        return $this->belongsTo(StockBucket::class, 'stock_bucket_id');
    }
}
