<?php

namespace App\Actions\Inventory;

use App\Actions\Audit\WriteAuditLog;
use App\Actions\Shifts\ResolveOperationalShift;
use App\Enums\InventoryPeriodStatus;
use App\Enums\StockTransactionStatus;
use App\Enums\StockTransactionType;
use App\Events\StockTransactionReversed;
use App\Models\InventoryPeriod;
use App\Models\StockBalance;
use App\Models\StockLot;
use App\Models\StockTransaction;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ReverseStockTransaction
{
    public function __construct(private ResolveOperationalShift $shifts, private WriteAuditLog $audit, private StockOpnameFreeze $freeze) {}

    public function handle(StockTransaction $original, User $admin, string $reason, string $idempotencyKey): StockTransaction
    {
        if (! $admin->isAdmin() || ! $admin->is_active || trim($reason) === '') {
            throw ValidationException::withMessages(['reversal' => 'Reversal membutuhkan Admin aktif dan alasan.']);
        }

        return DB::transaction(function () use ($original, $admin, $reason, $idempotencyKey): StockTransaction {
            $record = StockTransaction::whereKey($original->id)->lockForUpdate()->firstOrFail();
            if ($existing = StockTransaction::where('reversal_of_id', $record->id)->first()) {
                return $existing;
            }
            if ($record->status !== StockTransactionStatus::Posted || $record->type === StockTransactionType::Reversal) {
                throw ValidationException::withMessages(['reversal' => 'Hanya transaksi POSTED non-reversal yang dapat dibalik.']);
            }

            $postedAt = CarbonImmutable::now('UTC');
            $period = InventoryPeriod::where('status', InventoryPeriodStatus::Active)->where('cutoff_at', '<=', $postedAt)->orderByDesc('cutoff_at')->lockForUpdate()->first();
            if (! $period) {
                throw ValidationException::withMessages(['reversal' => 'Tidak ada periode persediaan ACTIVE.']);
            }
            $shift = $this->shifts->handle($postedAt);
            $reversal = StockTransaction::create([
                'inventory_period_id' => $period->id,
                'transaction_no' => 'RV-'.Str::uuid(),
                'type' => StockTransactionType::Reversal,
                'status' => StockTransactionStatus::Posted,
                'source_type' => 'stock-reversal',
                'source_id' => $record->id,
                'physical_occurred_at' => $postedAt,
                'posted_at' => $postedAt,
                'operational_shift_id' => $shift->id,
                'created_by' => $admin->id,
                'posted_by' => $admin->id,
                'idempotency_key' => $idempotencyKey,
                'reason' => trim($reason),
                'reversal_of_id' => $record->id,
            ]);

            foreach ($record->ledgerEntries()->with(['lot', 'lotAllocations'])->orderBy('entry_sequence')->get() as $entry) {
                $balance = StockBalance::where('stock_bucket_id', $entry->stock_bucket_id)->lockForUpdate()->firstOrFail();
                $this->freeze->assertNotFrozen($entry->stock_bucket_id);
                $quantity = $this->units($entry->quantity);
                $reversedUnits = -$quantity;
                $newBalance = $this->units($balance->quantity) + $reversedUnits;
                if ($newBalance < 0) {
                    throw ValidationException::withMessages(['reversal' => 'Saldo tidak cukup untuk membalik transaksi penerimaan.']);
                }
                $reversalEntry = $reversal->ledgerEntries()->create([
                    'stock_bucket_id' => $entry->stock_bucket_id,
                    'quantity' => $this->decimal($reversedUnits),
                    'entry_sequence' => $entry->entry_sequence,
                    'physical_occurred_at' => $postedAt,
                ]);

                if ($quantity > 0) {
                    $lot = StockLot::where('stock_ledger_entry_id', $entry->id)->lockForUpdate()->firstOrFail();
                    if ($this->units($lot->remaining_quantity) !== $quantity) {
                        throw ValidationException::withMessages(['reversal' => 'Penerimaan yang lot-nya sudah dipakai tidak dapat dibalik.']);
                    }
                    $reversalEntry->lotAllocations()->create(['stock_lot_id' => $lot->id, 'quantity' => $this->decimal($quantity)]);
                    $lot->update(['remaining_quantity' => '0.0000']);
                } else {
                    foreach ($entry->lotAllocations as $allocation) {
                        $lot = StockLot::whereKey($allocation->stock_lot_id)->lockForUpdate()->firstOrFail();
                        $lot->update(['remaining_quantity' => $this->decimal($this->units($lot->remaining_quantity) + $this->units($allocation->quantity))]);
                    }
                }
                $balance->update(['quantity' => $this->decimal($newBalance), 'version' => $balance->version + 1]);
            }

            $this->audit->handle($admin, 'stock_transaction.reversed', $reversal, $record->toArray(), $reversal->toArray(), trim($reason));
            DB::afterCommit(fn () => event(new StockTransactionReversed($record, $reversal)));

            return $reversal;
        }, 3);
    }

    private function units(string $quantity): int
    {
        $negative = str_starts_with($quantity, '-');
        [$whole, $fraction] = array_pad(explode('.', ltrim($quantity, '-'), 2), 2, '');
        $units = ((int) $whole * 10000) + (int) str_pad(substr($fraction, 0, 4), 4, '0');

        return $negative ? -$units : $units;
    }

    private function decimal(int $units): string
    {
        $sign = $units < 0 ? '-' : '';
        $absolute = abs($units);

        return $sign.intdiv($absolute, 10000).'.'.str_pad((string) ($absolute % 10000), 4, '0', STR_PAD_LEFT);
    }
}
