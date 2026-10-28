<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MasterDataImport extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['data' => 'array', 'options' => 'array', 'decisions' => 'array', 'corrections' => 'array', 'result' => 'array', 'expires_at' => 'datetime', 'confirmed_at' => 'datetime'];
    }
}
