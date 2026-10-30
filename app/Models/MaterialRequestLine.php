<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MaterialRequestLine extends Model
{
    use HasUuids;

    protected $guarded = [];

    public function materialRequest(): BelongsTo
    {
        return $this->belongsTo(MaterialRequest::class);
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    public function revisionLines(): HasMany
    {
        return $this->hasMany(MaterialRequestRevisionLine::class);
    }

    public function issueLines(): HasMany
    {
        return $this->hasMany(MaterialIssueLine::class);
    }
}
