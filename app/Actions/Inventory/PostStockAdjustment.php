<?php

namespace App\Actions\Inventory;

use App\Actions\Audit\WriteAuditLog;
use App\Data\StockPostingData;
use App\Data\StockPostingLineData;
use App\Enums\StockAdjustmentStatus;
use App\Enums\StockDirection;
use App\Enums\StockTransactionType;
use App\Models\StockAdjustment;
use App\Models\StockTransaction;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PostStockAdjustment
{
    public function __construct(private PostStockIn $stockIn, private PostStockOut $stockOut, private WriteAuditLog $audit) {}

    public function handle(StockAdjustment $adjustment, User $admin, string $idempotencyKey): StockTransaction
    {
        if (! $admin->isAdmin() || ! $admin->is_active) {
            throw ValidationException::withMessages(['authorization' => 'Hanya Admin aktif yang dapat memposting penyesuaian.']);
        }

        return DB::transaction(function () use ($adjustment, $admin, $idempotencyKey): StockTransaction {
            $record = StockAdjustment::whereKey($adjustment->id)->lockForUpdate()->firstOrFail();
            if ($record->status === StockAdjustmentStatus::Posted && $record->stock_transaction_id) {
                return StockTransaction::findOrFail($record->stock_transaction_id);
            }
            if ($record->status !== StockAdjustmentStatus::Approved) {
                throw ValidationException::withMessages(['adjustment' => 'Hanya penyesuaian APPROVED yang dapat diposting.']);
            }
            $before = $record->toArray();
            $data = new StockPostingData(
                StockTransactionType::Adjustment,
                $record->getMorphClass(),
                $record->id,
                now()->toImmutable(),
                $admin->id,
                $idempotencyKey,
                $record->reason,
                [new StockPostingLineData($record->item->id, $record->vendor_item_id, $record->location_id, $record->direction, $record->quantity)],
            );
            $transaction = $record->direction === StockDirection::In ? $this->stockIn->handle($data) : $this->stockOut->handle($data);
            $record->refresh()->update(['status' => StockAdjustmentStatus::Posted, 'stock_transaction_id' => $transaction->id]);
            $this->audit->handle($admin, 'stock_adjustment.posted', $record, $before, $record->fresh()->toArray(), $record->reason);

            return $transaction;
        });
    }
}
