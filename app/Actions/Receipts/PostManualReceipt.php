<?php

namespace App\Actions\Receipts;

use App\Actions\Inventory\PostStockIn;
use App\Data\StockPostingData;
use App\Data\StockPostingLineData;
use App\Enums\OwnershipType;
use App\Enums\StockDirection;
use App\Enums\StockTransactionType;
use App\Models\Item;
use App\Models\ManualReceipt;
use App\Models\StockItemLocation;
use App\Models\User;
use App\Models\VendorItem;
use App\Support\OperationalNumberGenerator;
use App\Support\WholeQuantity;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PostManualReceipt
{
    public function __construct(private PostStockIn $postStockIn, private OperationalNumberGenerator $numbers) {}

    /** @param list<array{item_id:string,vendor_item_id?:string|null,location_id:string,quantity:string|int|float}> $lines */
    public function handle(User $warehouse, string $reason, ?string $doNumber, string $receiver, CarbonImmutable $receivedAt, array $lines, string $idempotencyKey): ManualReceipt
    {
        if (! $warehouse->isWarehouse() || trim($reason) === '' || trim($receiver) === '' || trim($idempotencyKey) === '' || $lines === []) {
            throw ValidationException::withMessages(['receipt' => 'Alasan, penerima, waktu, dan minimal satu barang wajib diisi oleh Warehouse.']);
        }
        if ($existing = ManualReceipt::where('idempotency_key', $idempotencyKey)->first()) {
            return $existing->load('lines');
        }

        return DB::transaction(function () use ($warehouse, $reason, $doNumber, $receiver, $receivedAt, $lines, $idempotencyKey): ManualReceipt {
            if ($existing = ManualReceipt::where('idempotency_key', $idempotencyKey)->lockForUpdate()->first()) {
                return $existing->load('lines');
            }
            $postingLines = [];
            $receiptLines = [];
            $seen = [];
            foreach ($lines as $input) {
                $item = Item::active()->whereKey($input['item_id'] ?? '')->lockForUpdate()->first();
                $locationId = (string) ($input['location_id'] ?? '');
                if (! $item || ! WholeQuantity::isPositive($input['quantity'] ?? null) || $locationId === '') {
                    throw ValidationException::withMessages(['lines' => 'Barang aktif, lokasi, dan quantity satuan bulat positif wajib diisi.']);
                }
                $vendorItemId = null;
                if ($item->ownership_type === OwnershipType::Consignment) {
                    $relation = VendorItem::active()->whereKey($input['vendor_item_id'] ?? '')->where('item_id', $item->id)->first();
                    if (! $relation) {
                        throw ValidationException::withMessages(['lines' => 'Barang CONSIGNMENT wajib memakai pasangan Vendor yang aktif.']);
                    }
                    $vendorItemId = $relation->id;
                } elseif (($input['vendor_item_id'] ?? null) !== null || ! StockItemLocation::active()->where('item_id', $item->id)->where('location_id', $locationId)->exists()) {
                    throw ValidationException::withMessages(['lines' => 'Barang STOCK wajib memakai lokasi penempatan aktif tanpa Vendor.']);
                }
                $bucket = $item->id.'|'.($vendorItemId ?? 'stock').'|'.$locationId;
                if (isset($seen[$bucket])) {
                    throw ValidationException::withMessages(['lines' => 'Bucket barang, Vendor, dan lokasi tidak boleh duplikat.']);
                }
                $seen[$bucket] = true;
                $quantity = (string) $input['quantity'];
                $postingLines[] = new StockPostingLineData($item->id, $vendorItemId, $locationId, StockDirection::In, $quantity);
                $receiptLines[] = ['item_id' => $vendorItemId === null ? $item->id : null, 'vendor_item_id' => $vendorItemId, 'location_id' => $locationId, 'received_quantity' => $quantity];
            }
            $sequence = $this->numbers->next('manual_receipt');
            $receipt = ManualReceipt::create(['receipt_no' => sprintf('DIN/%s/%04d', now()->format('Ymd'), $sequence), 'vendor_do_number' => filled($doNumber) ? trim($doNumber) : null, 'reason' => trim($reason), 'receiver_name' => trim($receiver), 'received_at' => $receivedAt->utc(), 'received_by' => $warehouse->id, 'status' => 'DRAFT', 'idempotency_key' => $idempotencyKey]);
            foreach ($receiptLines as $data) {
                $receipt->lines()->create($data);
            }
            $transaction = $this->postStockIn->handle(new StockPostingData(StockTransactionType::ManualReceipt, ManualReceipt::class, $receipt->id, $receivedAt, $warehouse->id, $idempotencyKey, trim($reason), $postingLines));
            $receipt->update(['status' => 'POSTED', 'stock_transaction_id' => $transaction->id]);

            return $receipt->fresh(['lines', 'transaction']);
        });
    }
}
