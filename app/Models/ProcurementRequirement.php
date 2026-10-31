<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOneThrough;

class ProcurementRequirement extends Model
{
    use HasUuids;

    protected $guarded = [];

    public function issueLine(): BelongsTo
    {
        return $this->belongsTo(MaterialIssueLine::class, 'material_issue_line_id');
    }

    /** The Vendor pair, location and quantity of a requirement are those of its issue line. */
    public function vendorItem(): HasOneThrough
    {
        return $this->hasOneThrough(VendorItem::class, MaterialIssueLine::class, 'id', 'id', 'material_issue_line_id', 'vendor_item_id');
    }

    public function requiredQuantity(): int
    {
        return (int) $this->issueLine->issued_quantity;
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
