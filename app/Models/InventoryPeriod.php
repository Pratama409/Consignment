<?php

namespace App\Models;

use App\Enums\InventoryPeriodStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class InventoryPeriod extends Model
{
    use HasUuids;

    protected $fillable = ['cutoff_at', 'status', 'activated_by', 'activated_at'];

    protected $attributes = ['status' => 'DRAFT'];

    protected function casts(): array
    {
        return ['cutoff_at' => 'immutable_datetime', 'status' => InventoryPeriodStatus::class, 'activated_at' => 'immutable_datetime'];
    }

    public function activator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'activated_by');
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(StockTransaction::class);
    }

    public function auditLogs(): MorphMany
    {
        return $this->morphMany(AuditLog::class, 'entity');
    }
}
