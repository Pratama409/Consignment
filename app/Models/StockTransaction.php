<?php

namespace App\Models;

use App\Enums\StockTransactionStatus;
use App\Enums\StockTransactionType;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class StockTransaction extends Model
{
    use HasUuids;

    protected $fillable = ['inventory_period_id', 'transaction_no', 'type', 'status', 'source_type', 'source_id', 'physical_occurred_at', 'posted_at', 'operational_shift_id', 'created_by', 'posted_by', 'idempotency_key', 'reason', 'reversal_of_id', 'is_late_posting'];

    protected $attributes = ['status' => 'DRAFT', 'is_late_posting' => false];

    protected function casts(): array
    {
        return ['type' => StockTransactionType::class, 'status' => StockTransactionStatus::class, 'physical_occurred_at' => 'immutable_datetime', 'posted_at' => 'immutable_datetime', 'is_late_posting' => 'boolean'];
    }

    public function inventoryPeriod(): BelongsTo
    {
        return $this->belongsTo(InventoryPeriod::class);
    }

    public function operationalShift(): BelongsTo
    {
        return $this->belongsTo(OperationalShift::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function poster(): BelongsTo
    {
        return $this->belongsTo(User::class, 'posted_by');
    }

    public function ledgerEntries(): HasMany
    {
        return $this->hasMany(StockLedgerEntry::class);
    }

    public function reversalOf(): BelongsTo
    {
        return $this->belongsTo(StockTransaction::class, 'reversal_of_id');
    }

    public function reversal(): HasOne
    {
        return $this->hasOne(StockTransaction::class, 'reversal_of_id');
    }

    public function auditLogs(): MorphMany
    {
        return $this->morphMany(AuditLog::class, 'entity');
    }

    public function source(): MorphTo
    {
        return $this->morphTo();
    }
}
