<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MaterialRequestRevision extends Model
{
    use HasUuids;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['revised_at' => 'immutable_datetime'];
    }

    public function materialRequest(): BelongsTo
    {
        return $this->belongsTo(MaterialRequest::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(MaterialRequestRevisionLine::class);
    }

    public function revisedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'revised_by');
    }
}
