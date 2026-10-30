<?php

namespace App\Models;

use App\Enums\VendorReconciliationStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class VendorReconciliation extends Model
{
    use HasUuids;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['status' => VendorReconciliationStatus::class, 'period_start' => 'immutable_date', 'period_end' => 'immutable_date', 'source_cutoff_at' => 'immutable_datetime', 'finalized_at' => 'immutable_datetime'];
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(VendorReconciliationLine::class);
    }

    public function responses(): HasMany
    {
        return $this->hasMany(VendorReconciliationResponse::class);
    }

    public function documents(): HasMany
    {
        return $this->hasMany(VendorReconciliationDocument::class);
    }

    public function supersedes(): BelongsTo
    {
        return $this->belongsTo(self::class, 'supersedes_id');
    }
}
