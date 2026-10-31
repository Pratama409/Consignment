<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VendorRefillRevision extends Model
{
    use HasUuids;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['proposed_quantity' => 'decimal:4', 'reviewed_at' => 'immutable_datetime'];
    }

    public function line(): BelongsTo
    {
        return $this->belongsTo(VendorRefillLine::class, 'vendor_refill_line_id');
    }
}
