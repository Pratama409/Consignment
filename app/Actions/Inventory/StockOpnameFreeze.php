<?php

namespace App\Actions\Inventory;

use App\Enums\StockAdjustmentStatus;
use App\Enums\StockOpnameStatus;
use App\Models\StockOpnameLine;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * A bucket is frozen while an open Stock Opname holds a difference for it, so nothing but the
 * line's own Koreksi Stok may change it until the difference is resolved.
 */
class StockOpnameFreeze
{
    /**
     * Call after the bucket's balance row is locked: a count submit takes the same lock, so a posting
     * either lands before the snapshot or sees the freeze.
     */
    public function assertNotFrozen(string $stockBucketId, ?string $allowedAdjustmentId = null): void
    {
        $line = $this->frozenLines($allowedAdjustmentId)->where('stock_bucket_id', $stockBucketId)->with('session')->first();
        if ($line !== null) {
            throw ValidationException::withMessages(['stock' => 'Barang sedang dibekukan oleh Stock Opname '.$line->session->session_no.' sampai selisih diselesaikan.']);
        }
    }

    /** @return Collection<int, string> */
    public function frozenBucketIds(): Collection
    {
        return $this->frozenLines()->distinct()->pluck('stock_bucket_id');
    }

    private function frozenLines(?string $allowedAdjustmentId = null)
    {
        return StockOpnameLine::query()
            ->whereNotNull('system_quantity')
            // A reopened line (no count) stays frozen; a counted line is frozen only when it differs.
            ->where(fn ($line) => $line->whereNull('counted_quantity')->orWhereColumn('counted_quantity', '<>', 'system_quantity'))
            ->whereHas('session', fn ($session) => $session->whereIn('status', collect(StockOpnameStatus::cases())->filter->isOpen()->map->value->all()))
            ->whereDoesntHave('adjustment', fn ($adjustment) => $adjustment->where('status', StockAdjustmentStatus::Posted->value))
            ->when($allowedAdjustmentId !== null, fn ($query) => $query->where(fn ($line) => $line->whereNull('stock_adjustment_id')->orWhere('stock_adjustment_id', '<>', $allowedAdjustmentId)));
    }
}
