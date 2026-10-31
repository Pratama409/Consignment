<?php

namespace App\Models;

use App\Models\Concerns\ResolvesDirectOrVendorItem;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ManualReceiptLine extends Model
{
    use HasUuids, ResolvesDirectOrVendorItem;

    protected $guarded = [];

    public function receipt(): BelongsTo
    {
        return $this->belongsTo(ManualReceipt::class, 'manual_receipt_id');
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
