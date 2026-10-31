<?php

namespace App\Actions\Inventory;

use App\Models\StockLedgerEntry;
use App\Models\StockOpnameLine;
use Illuminate\Support\Collection;

/** Stock movements on a counted bucket after its count, so the admin sees what changed since. */
class OpnameMovementLog
{
    /** @return Collection<int, array{transaction_no: string, type: string, quantity: string, posted_at: string}> */
    public function forLine(StockOpnameLine $line): Collection
    {
        if ($line->counted_at === null) {
            return collect();
        }
        $own = $line->session->lines()->with('adjustment')->get()->pluck('adjustment.stock_transaction_id')->filter()->all();

        return StockLedgerEntry::query()
            ->where('stock_bucket_id', $line->stock_bucket_id)
            ->whereHas('transaction', fn ($transaction) => $transaction->where('posted_at', '>', $line->counted_at)->whereNotIn('id', $own))
            ->with('transaction')
            ->get()
            ->sortBy(fn ($entry) => $entry->transaction->posted_at)
            ->map(fn ($entry) => [
                'transaction_no' => $entry->transaction->transaction_no,
                'type' => $entry->transaction->type->value,
                'quantity' => $entry->quantity,
                'posted_at' => $entry->transaction->posted_at->timezone('Asia/Makassar')->format('d/m/Y H:i'),
            ])->values();
    }
}
