<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VendorReconciliationResponse extends Model
{
    use HasUuids;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['responded_at' => 'immutable_datetime'];
    }

    public function reconciliation(): BelongsTo
    {
        return $this->belongsTo(VendorReconciliation::class, 'vendor_reconciliation_id');
    }

    public function line(): BelongsTo
    {
        return $this->belongsTo(VendorReconciliationLine::class, 'vendor_reconciliation_line_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
