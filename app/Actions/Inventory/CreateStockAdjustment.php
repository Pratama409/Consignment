<?php

namespace App\Actions\Inventory;

use App\Actions\Audit\WriteAuditLog;
use App\Data\StockPostingLineData;
use App\Enums\StockDirection;
use App\Models\Item;
use App\Models\Location;
use App\Models\StockAdjustment;
use App\Models\User;
use App\Models\VendorItem;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

class CreateStockAdjustment
{
    public function __construct(private ResolveStockBucket $buckets, private WriteAuditLog $audit) {}

    public function handle(array $data, User $requester): StockAdjustment
    {
        if ((! $requester->isAdmin() && ! $requester->isWarehouse()) || ! $requester->is_active) {
            throw ValidationException::withMessages(['authorization' => 'Hanya Admin atau Warehouse aktif yang dapat mengajukan penyesuaian.']);
        }
        if (trim((string) ($data['reason'] ?? '')) === '') {
            throw ValidationException::withMessages(['reason' => 'Alasan penyesuaian wajib diisi.']);
        }

        try {
            $direction = StockDirection::from((string) ($data['direction'] ?? ''));
            $item = Item::active()->findOrFail($data['item_id'] ?? null);
            $location = Location::active()->findOrFail($data['location_id'] ?? null);
            $vendorItem = empty($data['vendor_item_id']) ? null : VendorItem::active()->findOrFail($data['vendor_item_id']);
            $line = new StockPostingLineData($item->id, $vendorItem?->id, $location->id, $direction, trim((string) ($data['quantity'] ?? '')));
            $this->buckets->handle($item, $vendorItem, $location);
        } catch (Throwable $exception) {
            if ($exception instanceof ValidationException) {
                throw $exception;
            }
            throw ValidationException::withMessages(['adjustment' => 'Barang, Vendor, lokasi, arah, atau quantity tidak valid.']);
        }

        return DB::transaction(function () use ($item, $vendorItem, $location, $line, $data, $requester): StockAdjustment {
            $adjustment = StockAdjustment::create([
                'item_id' => $item->id,
                'vendor_item_id' => $vendorItem?->id,
                'location_id' => $location->id,
                'direction' => $line->direction,
                'quantity' => $line->quantity,
                'reason' => trim($data['reason']),
                'requested_by' => $requester->id,
            ]);
            $this->audit->handle($requester, 'stock_adjustment.created', $adjustment, [], $adjustment->toArray(), $adjustment->reason);

            return $adjustment;
        });
    }
}
