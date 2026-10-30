<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class MaterialIssueLine extends Model
{
    use HasUuids;

    protected $guarded = [];

    public function issue(): BelongsTo
    {
        return $this->belongsTo(MaterialIssue::class, 'material_issue_id');
    }

    public function requestLine(): BelongsTo
    {
        return $this->belongsTo(MaterialRequestLine::class, 'material_request_line_id');
    }

    public function vendorItem(): BelongsTo
    {
        return $this->belongsTo(VendorItem::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function procurementRequirement(): HasOne
    {
        return $this->hasOne(ProcurementRequirement::class);
    }
}
