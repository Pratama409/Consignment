<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VendorReconciliationLine extends Model
{
    use HasUuids;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['opening_quantity' => 'decimal:4', 'in_quantity' => 'decimal:4', 'out_quantity' => 'decimal:4', 'adjustment_quantity' => 'decimal:4', 'system_closing_quantity' => 'decimal:4', 'physical_quantity' => 'decimal:4', 'variance_quantity' => 'decimal:4', 'references' => 'array'];
    }

    public function reconciliation(): BelongsTo
    {
        return $this->belongsTo(VendorReconciliation::class, 'vendor_reconciliation_id');
    }

    public function vendorItem(): BelongsTo
    {
        return $this->belongsTo(VendorItem::class);
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }
}
