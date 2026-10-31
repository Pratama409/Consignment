<?php

namespace App\Models;

use App\Enums\StockOpnameScope;
use App\Enums\StockOpnameStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StockOpnameSession extends Model
{
    use HasUuids;

    protected $fillable = ['session_no', 'scope_type', 'cycle_month', 'status', 'created_by', 'counted_by', 'approved_by', 'submitted_at', 'approved_at', 'completed_at', 'note'];

    protected $attributes = ['status' => 'COUNTING'];

    protected function casts(): array
    {
        return ['scope_type' => StockOpnameScope::class, 'status' => StockOpnameStatus::class, 'submitted_at' => 'immutable_datetime', 'approved_at' => 'immutable_datetime', 'completed_at' => 'immutable_datetime'];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(StockOpnameLine::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function counter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'counted_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}
