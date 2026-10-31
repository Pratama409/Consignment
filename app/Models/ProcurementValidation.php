<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class ProcurementValidation extends Model
{
    use HasUuids;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['validated_at' => 'immutable_datetime'];
    }
}
