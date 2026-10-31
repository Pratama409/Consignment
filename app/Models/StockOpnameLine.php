<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockOpnameLine extends Model
{
    use HasUuids;

    protected $fillable = ['stock_opname_session_id', 'stock_bucket_id', 'counted_quantity', 'system_quantity', 'counted_by', 'counted_at', 'stock_adjustment_id'];

    protected function casts(): array
    {
        return ['counted_quantity' => 'decimal:4', 'system_quantity' => 'decimal:4', 'counted_at' => 'immutable_datetime'];
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(StockOpnameSession::class, 'stock_opname_session_id');
    }

    public function bucket(): BelongsTo
    {
        return $this->belongsTo(StockBucket::class, 'stock_bucket_id');
    }

    public function adjustment(): BelongsTo
    {
        return $this->belongsTo(StockAdjustment::class, 'stock_adjustment_id');
    }

    public function counter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'counted_by');
    }

    /** Counted minus system, or null until the count and the system snapshot both exist. */
    public function difference(): ?string
    {
        if ($this->counted_quantity === null || $this->system_quantity === null) {
            return null;
        }

        return bcsub((string) $this->counted_quantity, (string) $this->system_quantity, 4);
    }

    public function state(): ?string
    {
        $difference = $this->difference();
        if ($difference === null) {
            return null;
        }

        return match (bccomp($difference, '0', 4)) {
            -1 => 'SHORT',
            1 => 'OVER',
            default => 'BALANCE',
        };
    }
}
