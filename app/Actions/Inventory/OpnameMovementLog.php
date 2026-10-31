<?php

namespace App\Actions\Inventory;

use App\Models\StockLedgerEntry;
use App\Models\StockOpnameLine;
use App\Models\StockOpnameSession;
use Illuminate\Support\Collection;

/** Stock movements on counted buckets after their count, so the admin sees what changed since. */
class OpnameMovementLog
{
    /**
     * One ledger query for the whole session, grouped by line id.
     *
     * @return Collection<string, Collection<int, array{transaction_no: string, type: string, quantity: string, posted_at: string}>>
     */
    public function forSession(StockOpnameSession $session): Collection
    {
        $lines = $session->lines()->with('adjustment')->get()->filter(fn (StockOpnameLine $line) => $line->counted_at !== null);
        if ($lines->isEmpty()) {
            return collect();
        }
        $own = $session->lines()->with('adjustment')->get()->pluck('adjustment.stock_transaction_id')->filter()->all();
        $entries = StockLedgerEntry::query()
            ->whereIn('stock_bucket_id', $lines->pluck('stock_bucket_id')->all())
            ->whereHas('transaction', fn ($transaction) => $transaction->where('posted_at', '>', $lines->min('counted_at'))->whereNotIn('id', $own))
            ->with('transaction')
            ->get()
            ->sortBy(fn ($entry) => $entry->transaction->posted_at);

        return $lines->mapWithKeys(fn (StockOpnameLine $line) => [$line->id => $entries
            ->filter(fn ($entry) => $entry->stock_bucket_id === $line->stock_bucket_id && $entry->transaction->posted_at->greaterThan($line->counted_at))
            ->map(fn ($entry) => [
                'transaction_no' => $entry->transaction->transaction_no,
                'type' => $entry->transaction->type->value,
                'quantity' => $entry->quantity,
                'posted_at' => $entry->transaction->posted_at->timezone('Asia/Makassar')->format('d/m/Y H:i'),
            ])->values()]);
    }

    /** @return Collection<int, array{transaction_no: string, type: string, quantity: string, posted_at: string}> */
    public function forLine(StockOpnameLine $line): Collection
    {
        return $this->forSession($line->session)->get($line->id, collect());
    }
}
