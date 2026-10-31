<?php

namespace App\Actions\Inventory;

use App\Actions\Audit\WriteAuditLog;
use App\Actions\Shifts\ResolveOperationalShift;
use App\Data\StockPostingData;
use App\Enums\InventoryPeriodStatus;
use App\Enums\StockDirection;
use App\Enums\StockTransactionStatus;
use App\Enums\StockTransactionType;
use App\Models\InventoryPeriod;
use App\Models\Item;
use App\Models\Location;
use App\Models\StockBalance;
use App\Models\StockItemLocation;
use App\Models\StockLot;
use App\Models\StockTransaction;
use App\Models\User;
use App\Models\Vendor;
use App\Models\VendorItem;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** Shared atomic implementation for the two directional posting entry points. */
class PostStock
{
    public function __construct(private ResolveStockBucket $buckets, private ResolveOperationalShift $shifts, private WriteAuditLog $audit, private StockOpnameFreeze $freeze) {}

    public function handle(StockPostingData $data, StockDirection $direction, ?InventoryPeriod $openingPeriod = null): StockTransaction
    {
        if (($openingPeriod !== null && ($data->type !== StockTransactionType::Opening || $direction !== StockDirection::In || DB::transactionLevel() < 1))
            || ($openingPeriod === null && $data->type === StockTransactionType::Opening)) {
            $this->invalid('Saldo awal hanya boleh diposting dalam transaksi aktivasi periode.');
        }
        if (! Str::isUuid($data->sourceId) || ! Str::isUuid($data->actorId)
            || trim($data->sourceType) === '' || strlen($data->sourceType) > 100
            || trim($data->idempotencyKey) === '' || strlen($data->idempotencyKey) > 100) {
            $this->invalid('Identitas sumber, aktor, dan idempotency key tidak valid.');
        }
        foreach ($data->lines as $line) {
            if ($line->direction !== $direction) {
                $this->invalid('Arah baris tidak sesuai dengan aksi posting.');
            }
        }
        // Returning immutable history is not a new posting and survives period closure.
        if ($openingPeriod === null && ($existing = $this->existing($data))) {
            return $existing;
        }

        try {
            return DB::transaction(function () use ($data, $direction, $openingPeriod): StockTransaction {
                $postedAt = CarbonImmutable::now('UTC');
                // The period is also the mutex for new balance rows and source-only references.
                // All postings acquire it before source, master, balance and lot locks.
                $period = $openingPeriod !== null
                    ? InventoryPeriod::whereKey($openingPeriod->id)->lockForUpdate()->first()
                    : InventoryPeriod::where('status', InventoryPeriodStatus::Active)->where('cutoff_at', '<=', $postedAt)->orderByDesc('cutoff_at')->lockForUpdate()->first();
                if (! $period || ($openingPeriod !== null && $period->status !== InventoryPeriodStatus::Draft)) {
                    $this->invalid('Periode persediaan yang sesuai belum aktif atau saldo awal tidak lagi DRAFT.');
                }

                if ($existing = $this->existing($data, true)) {
                    return $this->validateOpeningRetryPeriod($existing, $openingPeriod);
                }
                $source = $this->source($data);
                if ($openingPeriod !== null && $source !== null && $source->getAttribute('inventory_period_id') !== $period->id) {
                    $this->invalid('Batch saldo awal tidak sesuai periode yang dikunci.');
                }
                if ($source !== null && $source->getAttribute('stock_transaction_id') !== null) {
                    $this->invalid('Sumber sudah terhubung ke transaksi persediaan lain.');
                }
                $actor = User::whereKey($data->actorId)->first();
                if (! $actor || ! $actor->is_active) {
                    $this->invalid('Aktor posting harus aktif.');
                }
                $shift = $this->shifts->handle($postedAt);
                $transaction = StockTransaction::create([
                    'inventory_period_id' => $period->id, 'transaction_no' => 'ST-'.Str::uuid(),
                    'type' => $data->type, 'status' => StockTransactionStatus::Posted,
                    'source_type' => $data->sourceType, 'source_id' => $data->sourceId,
                    'physical_occurred_at' => $data->physicalOccurredAt->utc(), 'posted_at' => $postedAt,
                    'operational_shift_id' => $shift->id, 'created_by' => $actor->id, 'posted_by' => $actor->id,
                    'idempotency_key' => $data->idempotencyKey, 'reason' => $data->reason,
                    'is_late_posting' => $data->physicalOccurredAt->lessThan($shift->starts_at),
                ]);

                foreach ($data->lines as $index => $line) {
                    $item = Item::whereKey($line->itemId)->lockForUpdate()->first();
                    $location = Location::whereKey($line->locationId)->lockForUpdate()->first();
                    $vendorItem = $line->vendorItemId === null ? null : VendorItem::whereKey($line->vendorItemId)->lockForUpdate()->first();
                    if (! $item || ! $location || ($line->vendorItemId !== null && ! $vendorItem)) {
                        $this->invalid('Barang, lokasi, atau pasangan Vendor tidak ditemukan.');
                    }
                    if ($vendorItem !== null) {
                        Vendor::whereKey($vendorItem->vendor_id)->lockForUpdate()->first();
                    } else {
                        StockItemLocation::where('item_id', $item->id)->where('location_id', $location->id)->lockForUpdate()->get();
                    }
                    $bucket = $this->buckets->handle($item, $vendorItem, $location);
                    $balance = StockBalance::where('stock_bucket_id', $bucket->id)->lockForUpdate()->first();
                    if (! $balance) {
                        $balance = StockBalance::create(['stock_bucket_id' => $bucket->id]);
                    }
                    $this->freeze->assertNotFrozen($bucket->id, $data->type === StockTransactionType::Adjustment ? $data->sourceId : null);
                    $quantity = $this->units($line->quantity);
                    $remaining = $this->units($balance->quantity) + ($direction === StockDirection::In ? $quantity : -$quantity);
                    if ($remaining < 0) {
                        $this->invalid('Stok tidak mencukupi.', 'quantity');
                    }
                    if ($remaining > 99999999999999) {
                        $this->invalid('Saldo melebihi batas quantity yang didukung.', 'quantity');
                    }
                    $entry = $transaction->ledgerEntries()->create([
                        'stock_bucket_id' => $bucket->id,
                        'quantity' => ($direction === StockDirection::Out ? '-' : '').$line->quantity,
                        'entry_sequence' => $index + 1, 'physical_occurred_at' => $data->physicalOccurredAt->utc(),
                    ]);
                    if ($direction === StockDirection::In) {
                        $entry->lot()->create(['stock_bucket_id' => $bucket->id, 'received_at' => $postedAt, 'original_quantity' => $line->quantity, 'remaining_quantity' => $line->quantity]);
                    } else {
                        $lots = StockLot::where('stock_bucket_id', $bucket->id)->where('remaining_quantity', '>', 0)->orderBy('received_at')->orderBy('id')->lockForUpdate()->get();
                        $toAllocate = $quantity;
                        foreach ($lots as $lot) {
                            $available = $this->units($lot->remaining_quantity);
                            $allocated = min($available, $toAllocate);
                            $entry->lotAllocations()->create(['stock_lot_id' => $lot->id, 'quantity' => $this->decimal($allocated)]);
                            $lot->update(['remaining_quantity' => $this->decimal($available - $allocated)]);
                            $toAllocate -= $allocated;
                            if ($toAllocate === 0) {
                                break;
                            }
                        }
                        if ($toAllocate !== 0) {
                            $this->invalid('Lot stok tidak mencukupi.', 'quantity');
                        }
                    }
                    $balance->update(['quantity' => $this->decimal($remaining), 'version' => $balance->version + 1]);
                }
                if ($source !== null && array_key_exists('stock_transaction_id', $source->getAttributes())) {
                    $source->setAttribute('stock_transaction_id', $transaction->id);
                    $source->save();
                }
                $this->audit->handle($actor, 'stock.posted', $transaction, [], $transaction->toArray(), $data->reason);

                return $transaction;
            }, 3);
        } catch (QueryException $exception) {
            // Re-read after rollback so a concurrent unique-key winner becomes the retry result.
            // Unrelated database failures remain errors rather than successful duplicate requests.
            if (in_array((string) ($exception->errorInfo[0] ?? ''), ['23000', '23505'], true) && ($existing = $this->existing($data, true))) {
                return $this->validateOpeningRetryPeriod($existing, $openingPeriod);
            }

            throw $exception;
        }
    }

    private function existing(StockPostingData $data, bool $lock = false): ?StockTransaction
    {
        $byKey = StockTransaction::where('idempotency_key', $data->idempotencyKey)->when($lock, fn ($query) => $query->lockForUpdate())->first();
        $bySource = StockTransaction::where('source_type', $data->sourceType)->where('source_id', $data->sourceId)->when($lock, fn ($query) => $query->lockForUpdate())->first();
        $existing = $byKey ?? $bySource;
        if ($existing !== null && ($existing->source_type !== $data->sourceType || $existing->source_id !== $data->sourceId
            || $existing->type !== $data->type || $existing->status !== StockTransactionStatus::Posted
            || ($byKey !== null && $bySource !== null && $byKey->id !== $bySource->id))) {
            $this->invalid('Idempotency key atau sumber sudah digunakan transaksi berbeda.');
        }

        return $existing;
    }

    private function validateOpeningRetryPeriod(StockTransaction $existing, ?InventoryPeriod $openingPeriod): StockTransaction
    {
        if ($openingPeriod !== null && $existing->inventory_period_id !== $openingPeriod->id) {
            $this->invalid('Retry saldo awal tidak sesuai periode yang dikunci.');
        }

        return $existing;
    }

    private function source(StockPostingData $data): ?Model
    {
        $class = Relation::getMorphedModel($data->sourceType) ?? $data->sourceType;
        if (! is_subclass_of($class, Model::class)) {
            return null;
        }
        $source = $class::whereKey($data->sourceId)->lockForUpdate()->first();
        if (! $source) {
            $this->invalid('Dokumen sumber tidak ditemukan.');
        }

        return $source;
    }

    private function units(string $quantity): int
    {
        [$whole, $fraction] = explode('.', $quantity);

        return ((int) $whole * 10000) + (int) $fraction;
    }

    private function decimal(int $units): string
    {
        return intdiv($units, 10000).'.'.str_pad((string) ($units % 10000), 4, '0', STR_PAD_LEFT);
    }

    private function invalid(string $message, string $field = 'stock'): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
