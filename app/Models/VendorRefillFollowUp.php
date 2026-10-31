<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A reminder from the warehouse or admin that a partially received refill still owes quantity. */
class VendorRefillFollowUp extends Model
{
    use HasUuids;

    protected $fillable = ['vendor_refill_id', 'requested_by', 'note', 'acknowledged_by', 'acknowledged_at'];

    protected function casts(): array
    {
        return ['acknowledged_at' => 'immutable_datetime'];
    }

    public function refill(): BelongsTo
    {
        return $this->belongsTo(VendorRefill::class, 'vendor_refill_id');
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }
}
