<?php

namespace App\Models;

use App\Enums\StockAdjustmentStatus;
use App\Enums\StockDirection;
use App\Models\Concerns\ResolvesDirectOrVendorItem;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockAdjustment extends Model
{
    use HasUuids, ResolvesDirectOrVendorItem;

    protected $fillable = ['status', 'item_id', 'vendor_item_id', 'location_id', 'direction', 'quantity', 'reason', 'review_note', 'requested_by', 'reviewed_by', 'reviewed_at', 'stock_transaction_id'];

    protected $attributes = ['status' => 'DRAFT'];

    protected function casts(): array
    {
        return ['status' => StockAdjustmentStatus::class, 'direction' => StockDirection::class, 'quantity' => 'decimal:4', 'reviewed_at' => 'immutable_datetime'];
    }

    public function vendorItem(): BelongsTo
    {
        return $this->belongsTo(VendorItem::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(StockTransaction::class, 'stock_transaction_id');
    }
}
