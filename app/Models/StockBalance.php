<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockBalance extends Model
{
    use HasUuids;

    protected $fillable = ['item_id', 'vendor_item_id', 'location_id', 'bucket_key', 'quantity', 'version'];

    protected $attributes = ['quantity' => '0.0000', 'version' => 0];

    protected function casts(): array
    {
        return ['quantity' => 'decimal:4', 'version' => 'integer'];
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    public function vendorItem(): BelongsTo
    {
        return $this->belongsTo(VendorItem::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }
}
