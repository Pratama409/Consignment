<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class ProcurementImportRow extends Model
{
    use HasUuids;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['source_data' => 'array', 'errors' => 'array', 'applied_at' => 'immutable_datetime'];
    }
}
