<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MaterialRequest extends Model
{
    use HasUuids;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['needed_at' => 'immutable_datetime', 'submitted_at' => 'immutable_datetime', 'cancelled_at' => 'immutable_datetime'];
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(MaterialRequestLine::class);
    }

    public function revisions(): HasMany
    {
        return $this->hasMany(MaterialRequestRevision::class);
    }

    public function approvals(): HasMany
    {
        return $this->hasMany(MaterialRequestApproval::class);
    }

    public function issues(): HasMany
    {
        return $this->hasMany(MaterialIssue::class);
    }
}
