<?php

namespace App\Actions\OpeningBalance;

use App\Actions\Inventory\PostStockIn;
use App\Data\StockPostingData;
use App\Data\StockPostingLineData;
use App\Enums\InventoryPeriodStatus;
use App\Enums\OpeningBalanceBatchStatus;
use App\Enums\StockDirection;
use App\Enums\StockTransactionType;
use App\Models\InventoryPeriod;
use App\Models\OpeningBalanceBatch;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ActivateOpeningBalance
{
    public function __construct(private PostStockIn $postStockIn) {}

    public function handle(OpeningBalanceBatch $batch, User $admin, string $idempotencyKey): InventoryPeriod
    {
        if (! $admin->isAdmin() || ! $admin->is_active) {
            throw ValidationException::withMessages(['authorization' => 'Hanya Admin aktif yang dapat mengaktifkan saldo awal.']);
        }

        return DB::transaction(function () use ($batch, $admin, $idempotencyKey): InventoryPeriod {
            $control = DB::table('inventory_period_controls')->where('control_key', 'GLOBAL')->lockForUpdate()->first();
            if (! $control) {
                throw ValidationException::withMessages(['activation' => 'Kontrol periode persediaan tidak tersedia.']);
            }
            $lockedBatch = OpeningBalanceBatch::whereKey($batch->id)->lockForUpdate()->firstOrFail();
            $period = InventoryPeriod::whereKey($lockedBatch->inventory_period_id)->lockForUpdate()->firstOrFail();
            if ($lockedBatch->status !== OpeningBalanceBatchStatus::Preview || $lockedBatch->stock_transaction_id !== null || $period->status !== InventoryPeriodStatus::Draft) {
                throw ValidationException::withMessages(['activation' => 'Preview sudah diaktifkan atau periode tidak lagi DRAFT.']);
            }
            if (($control->active_period_id !== null && $control->active_period_id !== $period->id)
                || InventoryPeriod::where('status', InventoryPeriodStatus::Active)->whereKeyNot($period->id)->exists()) {
                throw ValidationException::withMessages(['activation' => 'Tutup periode aktif sebelum mengaktifkan saldo awal baru.']);
            }

            $lines = $lockedBatch->lines()->with('bucket.vendorItem')->orderBy('row_number')->get();
            if ($lines->isEmpty()) {
                throw ValidationException::withMessages(['activation' => 'Preview saldo awal tidak mempunyai baris.']);
            }
            $posting = new StockPostingData(
                StockTransactionType::Opening,
                $lockedBatch->getMorphClass(),
                $lockedBatch->id,
                $period->cutoff_at,
                $admin->id,
                $idempotencyKey,
                'Aktivasi saldo awal '.$lockedBatch->source,
                $lines->map(fn ($line) => new StockPostingLineData($line->bucket->vendorItem?->item_id ?? $line->bucket->item_id, $line->bucket->vendor_item_id, $line->bucket->location_id, StockDirection::In, $line->quantity))->all(),
            );
            $transaction = $this->postStockIn->handleOpening($posting, $period);
            $lockedBatch->refresh();
            $lockedBatch->update([
                'status' => OpeningBalanceBatchStatus::Activated,
                'stock_transaction_id' => $transaction->id,
                'activated_by' => $admin->id,
                'activated_at' => now(),
            ]);
            $period->update(['status' => InventoryPeriodStatus::Active, 'activated_by' => $admin->id, 'activated_at' => now()]);
            DB::table('inventory_period_controls')->where('id', $control->id)->update(['active_period_id' => $period->id, 'updated_at' => now()]);

            return $period->fresh();
        });
    }
}
