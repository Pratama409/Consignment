<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class VendorRefillReceipt extends Model
{
    use HasUuids;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['received_at' => 'immutable_datetime'];
    }

    public function refill(): BelongsTo
    {
        return $this->belongsTo(VendorRefill::class, 'vendor_refill_id');
    }

    public function shipment(): BelongsTo
    {
        return $this->belongsTo(VendorRefillShipment::class, 'vendor_refill_shipment_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(VendorRefillReceiptLine::class);
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(StockTransaction::class, 'stock_transaction_id');
    }
}
