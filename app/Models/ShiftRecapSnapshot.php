<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ShiftRecapSnapshot extends Model
{
    use HasUuids;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['summary' => 'array', 'closed_at' => 'immutable_datetime'];
    }

    public function operationalShift(): BelongsTo
    {
        return $this->belongsTo(OperationalShift::class);
    }

    public function generator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'generated_by');
    }
}
