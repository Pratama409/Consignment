<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class VendorRefillLine extends Model
{
    use HasUuids;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['requested_quantity' => 'decimal:4'];
    }

    public function refill(): BelongsTo
    {
        return $this->belongsTo(VendorRefill::class, 'vendor_refill_id');
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    public function revisions(): HasMany
    {
        return $this->hasMany(VendorRefillRevision::class);
    }

    public function receiptLines(): HasMany
    {
        return $this->hasMany(VendorRefillReceiptLine::class);
    }
}
