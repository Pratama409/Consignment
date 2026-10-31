<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VendorRefillShipmentLine extends Model
{
    use HasUuids;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['shipped_quantity' => 'decimal:4'];
    }

    public function shipment(): BelongsTo
    {
        return $this->belongsTo(VendorRefillShipment::class, 'vendor_refill_shipment_id');
    }

    public function refillLine(): BelongsTo
    {
        return $this->belongsTo(VendorRefillLine::class, 'vendor_refill_line_id');
    }
}
