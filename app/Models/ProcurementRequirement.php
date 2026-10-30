<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProcurementRequirement extends Model
{
    use HasUuids;

    protected $guarded = [];

    public function issueLine(): BelongsTo
    {
        return $this->belongsTo(MaterialIssueLine::class, 'material_issue_line_id');
    }

    public function vendorItem(): BelongsTo
    {
        return $this->belongsTo(VendorItem::class);
    }

    public function documentLines(): HasMany
    {
        return $this->hasMany(ProcurementDocumentLine::class);
    }

    public function validations(): HasMany
    {
        return $this->hasMany(ProcurementValidation::class);
    }

    public function documents(): HasMany
    {
        return $this->hasMany(ProcurementDocument::class);
    }
}
