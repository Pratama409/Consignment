<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VendorRefillReceiptLine extends Model
{
    use HasUuids;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['received_quantity' => 'decimal:4'];
    }

    public function receipt(): BelongsTo
    {
        return $this->belongsTo(VendorRefillReceipt::class, 'vendor_refill_receipt_id');
    }

    public function refillLine(): BelongsTo
    {
        return $this->belongsTo(VendorRefillLine::class, 'vendor_refill_line_id');
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }
}
