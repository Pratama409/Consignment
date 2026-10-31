<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProcurementDocument extends Model
{
    use HasUuids;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['is_vendor_visible' => 'boolean'];
    }

    public function requirement(): BelongsTo
    {
        return $this->belongsTo(ProcurementRequirement::class, 'procurement_requirement_id');
    }
}
