<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VendorReconciliationDocument extends Model
{
    use HasUuids;

    protected $guarded = [];

    public function reconciliation(): BelongsTo
    {
        return $this->belongsTo(VendorReconciliation::class, 'vendor_reconciliation_id');
    }
}
