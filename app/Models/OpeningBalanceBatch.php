<?php

namespace App\Models;

use App\Enums\OpeningBalanceBatchStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OpeningBalanceBatch extends Model
{
    use HasUuids;

    protected $fillable = ['inventory_period_id', 'status', 'source', 'created_by', 'activated_by', 'activated_at', 'stock_transaction_id'];

    protected $attributes = ['status' => 'PREVIEW'];

    protected function casts(): array
    {
        return ['status' => OpeningBalanceBatchStatus::class, 'activated_at' => 'immutable_datetime'];
    }

    public function period(): BelongsTo
    {
        return $this->belongsTo(InventoryPeriod::class, 'inventory_period_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(OpeningBalanceLine::class);
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(StockTransaction::class, 'stock_transaction_id');
    }
}
