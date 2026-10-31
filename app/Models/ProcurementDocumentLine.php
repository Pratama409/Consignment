<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProcurementDocumentLine extends Model
{
    use HasUuids;

    protected $guarded = [];

    protected $appends = ['document_no'];

    public function reference(): BelongsTo
    {
        return $this->belongsTo(ProcurementReference::class, 'procurement_reference_id');
    }

    public function getDocumentNoAttribute(): ?string
    {
        return $this->reference?->document_no;
    }

    public function requirement(): BelongsTo
    {
        return $this->belongsTo(ProcurementRequirement::class, 'procurement_requirement_id');
    }
}
