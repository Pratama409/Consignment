<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MaterialIssue extends Model
{
    use HasUuids;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['issued_at' => 'immutable_datetime'];
    }

    public function materialRequest(): BelongsTo
    {
        return $this->belongsTo(MaterialRequest::class);
    }

    public function issuedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by');
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(StockTransaction::class, 'stock_transaction_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(MaterialIssueLine::class);
    }
}
