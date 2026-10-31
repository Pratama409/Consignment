<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProcurementImportBatch extends Model
{
    use HasUuids;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['version' => 'integer'];
    }

    public function rows(): HasMany
    {
        return $this->hasMany(ProcurementImportRow::class);
    }
}
