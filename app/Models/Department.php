<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Department extends Model
{
    use HasUuids;

    protected $guarded = [];

    public function materialRequests(): HasMany
    {
        return $this->hasMany(MaterialRequest::class);
    }
}
