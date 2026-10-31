<?php

namespace App\Actions\Inventory;

use App\Enums\StockAdjustmentStatus;
use App\Enums\StockOpnameStatus;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * A bucket is frozen while an open Stock Opname holds a difference for it, so nothing but the
 * line's own Koreksi Stok may change it until the difference is resolved.
 */
class StockOpnameFreeze
{
    /**
     * Call after the bucket's balance row is locked: a count submit takes the same lock, so a posting
     * either lands before the snapshot or sees the freeze. The read is a locking read on one join: under
     * REPEATABLE READ a plain read (or a subquery) would use the transaction's older snapshot and miss
     * a count submitted while this posting waited for the balance lock.
     */
    public function assertNotFrozen(string $stockBucketId, ?string $allowedAdjustmentId = null): void
    {
        $sessionNo = $this->frozenLines($allowedAdjustmentId)->where('l.stock_bucket_id', $stockBucketId)->lockForUpdate()->value('s.session_no');
        if ($sessionNo !== null) {
            throw ValidationException::withMessages(['stock' => 'Barang sedang dibekukan oleh Stock Opname '.$sessionNo.' sampai selisih diselesaikan.']);
        }
    }

    /** @return Collection<int, string> */
    public function frozenBucketIds(): Collection
    {
        return $this->frozenLines()->distinct()->pluck('l.stock_bucket_id');
    }

    private function frozenLines(?string $allowedAdjustmentId = null): Builder
    {
        return DB::table('stock_opname_lines as l')
            ->join('stock_opname_sessions as s', 's.id', '=', 'l.stock_opname_session_id')
            ->leftJoin('stock_adjustments as a', 'a.id', '=', 'l.stock_adjustment_id')
            ->whereNotNull('l.system_quantity')
            // A reopened line (no count) stays frozen; a counted line is frozen only when it differs.
            ->where(fn ($line) => $line->whereNull('l.counted_quantity')->orWhereColumn('l.counted_quantity', '<>', 'l.system_quantity'))
            ->whereIn('s.status', collect(StockOpnameStatus::cases())->filter->isOpen()->map->value->all())
            ->where(fn ($adjustment) => $adjustment->whereNull('a.id')->orWhere('a.status', '<>', StockAdjustmentStatus::Posted->value))
            ->when($allowedAdjustmentId !== null, fn ($query) => $query->where(fn ($line) => $line->whereNull('l.stock_adjustment_id')->orWhere('l.stock_adjustment_id', '<>', $allowedAdjustmentId)));
    }
}
