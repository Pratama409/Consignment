<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class VendorRefill extends Model
{
    use HasUuids;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['requested_at' => 'immutable_datetime', 'closed_at' => 'immutable_datetime'];
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(VendorRefillLine::class);
    }

    public function shipments(): HasMany
    {
        return $this->hasMany(VendorRefillShipment::class);
    }

    public function receipts(): HasMany
    {
        return $this->hasMany(VendorRefillReceipt::class);
    }
}
