<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class VendorRefillShipment extends Model
{
    use HasUuids;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['shipped_at' => 'immutable_datetime', 'expected_arrival_date' => 'immutable_date'];
    }

    public function refill(): BelongsTo
    {
        return $this->belongsTo(VendorRefill::class, 'vendor_refill_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(VendorRefillShipmentLine::class);
    }
}
