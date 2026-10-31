<?php

namespace App\Actions\Refills;

use App\Actions\Inventory\PostStockIn;
use App\Data\StockPostingData;
use App\Data\StockPostingLineData;
use App\Enums\StockDirection;
use App\Enums\StockTransactionType;
use App\Models\User;
use App\Models\VendorItem;
use App\Models\VendorRefill;
use App\Models\VendorRefillLine;
use App\Models\VendorRefillReceipt;
use App\Models\VendorRefillShipment;
use App\Support\OperationalNumberGenerator;
use App\Support\WholeQuantity;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PostRefillReceipt
{
    public function __construct(private PostStockIn $postStockIn, private OperationalNumberGenerator $numbers) {}

    /** @param list<array{refill_line_id:string,location_id:string,quantity:string|int|float}> $lines */
    public function handle(VendorRefillShipment $shipment, User $warehouse, string $doNumber, CarbonImmutable $receivedAt, array $lines, string $idempotencyKey): VendorRefillReceipt
    {
        if ($existing = VendorRefillReceipt::where('idempotency_key', $idempotencyKey)->first()) {
            return $existing;
        }
        if (! $warehouse->isWarehouse() || trim($doNumber) === '' || trim($idempotencyKey) === '' || $lines === []) {
            throw ValidationException::withMessages(['receipt' => 'Data Incoming Refill belum lengkap.']);
        }

        return DB::transaction(function () use ($shipment, $warehouse, $doNumber, $receivedAt, $lines, $idempotencyKey): VendorRefillReceipt {
            if ($existing = VendorRefillReceipt::where('idempotency_key', $idempotencyKey)->lockForUpdate()->first()) {
                return $existing;
            }
            $lockedShipment = VendorRefillShipment::query()->lockForUpdate()->findOrFail($shipment->id);
            $refill = VendorRefill::query()->lockForUpdate()->findOrFail($lockedShipment->vendor_refill_id);
            if (in_array($refill->status, ['COMPLETED', 'CLOSED_PARTIAL', 'CANCELLED'], true)) {
                throw ValidationException::withMessages(['receipt' => 'Refill sudah selesai atau ditutup.']);
            }
            $shipmentLines = $lockedShipment->lines()->lockForUpdate()->get()->keyBy('vendor_refill_line_id');
            $submitted = [];
            $buckets = [];
            foreach ($lines as $input) {
                $lineId = (string) ($input['refill_line_id'] ?? '');
                if (! WholeQuantity::isPositive($input['quantity'] ?? null)) {
                    throw ValidationException::withMessages(['receipt' => 'Quantity penerimaan harus berupa satuan bulat lebih dari nol.']);
                }
                $units = $this->units((string) ($input['quantity'] ?? '0'));
                $bucket = $lineId.'|'.($input['location_id'] ?? '');
                if ($units <= 0 || isset($buckets[$bucket])) {
                    throw ValidationException::withMessages(['receipt' => 'Baris penerimaan harus unik dan quantity lebih dari nol.']);
                }
                $buckets[$bucket] = true;
                $submitted[$lineId] = ($submitted[$lineId] ?? 0) + $units;
            }
            foreach ($submitted as $lineId => $units) {
                $line = VendorRefillLine::where('vendor_refill_id', $refill->id)->whereKey($lineId)->lockForUpdate()->first();
                $shipmentLine = $shipmentLines->get($lineId);
                $receivedForRefill = $line?->receiptLines()->whereHas('receipt', fn ($query) => $query->where('status', 'POSTED'))->sum('received_quantity') ?? 0;
                $receivedForShipment = $line?->receiptLines()->whereHas('receipt', fn ($query) => $query->where('status', 'POSTED')->where('vendor_refill_shipment_id', $lockedShipment->id))->sum('received_quantity') ?? 0;
                if (! $line || ! $shipmentLine || $this->units((string) $receivedForRefill) + $units > $this->units($line->requested_quantity) || $this->units((string) $receivedForShipment) + $units > $this->units($shipmentLine->shipped_quantity)) {
                    throw ValidationException::withMessages(['receipt' => 'Quantity penerimaan melebihi outstanding request atau shipment.']);
                }
            }
            $postingLines = [];
            $receiptData = [];
            foreach ($lines as $input) {
                $line = VendorRefillLine::where('vendor_refill_id', $refill->id)->whereKey($input['refill_line_id'] ?? '')->lockForUpdate()->first();
                $quantity = (string) ($input['quantity'] ?? '0');
                if (! $line) {
                    throw ValidationException::withMessages(['receipt' => 'Baris refill tidak ditemukan.']);
                }
                $relation = VendorItem::active()->where('vendor_id', $refill->vendor_id)->where('item_id', $line->item_id)->first();
                if (! $relation) {
                    throw ValidationException::withMessages(['receipt' => 'Relasi barang dan Vendor tidak aktif.']);
                }
                $postingLines[] = new StockPostingLineData($line->item_id, $relation->id, (string) $input['location_id'], StockDirection::In, $quantity);
                $receiptData[] = ['vendor_refill_line_id' => $line->id, 'location_id' => $input['location_id'], 'received_quantity' => $quantity];
            }
            $sequence = $this->numbers->next('refill_receipt');
            $receipt = $refill->receipts()->create(['receipt_no' => sprintf('RCV/%s/%04d', now()->format('Ymd'), $sequence), 'vendor_refill_shipment_id' => $lockedShipment->id, 'vendor_do_number' => trim($doNumber), 'received_at' => $receivedAt->utc(), 'received_by' => $warehouse->id, 'status' => 'DRAFT', 'idempotency_key' => $idempotencyKey]);
            foreach ($receiptData as $data) {
                $receipt->lines()->create($data);
            }
            $transaction = $this->postStockIn->handle(new StockPostingData(StockTransactionType::RefillReceipt, VendorRefillReceipt::class, $receipt->id, $receivedAt, $warehouse->id, $idempotencyKey, "Incoming {$refill->refill_no}", $postingLines));
            $receipt->update(['status' => 'POSTED', 'stock_transaction_id' => $transaction->id]);

            $complete = $refill->lines()->get()->every(fn (VendorRefillLine $line) => (float) $line->receiptLines()->whereHas('receipt', fn ($query) => $query->where('status', 'POSTED'))->sum('received_quantity') >= (float) $line->requested_quantity);
            $refill->update(['status' => $complete ? 'COMPLETED' : 'PARTIAL']);
            $shipmentComplete = $lockedShipment->lines()->get()->every(fn ($line) => (float) $line->refillLine->receiptLines()->whereHas('receipt', fn ($query) => $query->where('status', 'POSTED')->where('vendor_refill_shipment_id', $lockedShipment->id))->sum('received_quantity') >= (float) $line->shipped_quantity);
            $lockedShipment->update(['status' => $shipmentComplete ? 'RECEIVED' : 'PARTIALLY_RECEIVED']);

            return $receipt->fresh(['lines', 'transaction']);
        });
    }

    private function units(string $quantity): int
    {
        $normalized = number_format((float) $quantity, 4, '.', '');
        [$whole, $fraction] = explode('.', $normalized);

        return ((int) $whole * 10000) + (int) $fraction;
    }
}
