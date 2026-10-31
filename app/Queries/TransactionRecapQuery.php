<?php

namespace App\Queries;

use App\Models\MaterialIssue;
use App\Models\StockLedgerEntry;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class TransactionRecapQuery
{
    public function build(User $viewer, array $filters): Builder
    {
        return StockLedgerEntry::query()
            ->select('stock_ledger_entries.*')
            ->join('stock_transactions as recap_transactions', 'recap_transactions.id', '=', 'stock_ledger_entries.stock_transaction_id')
            ->withBucketIdentity()->with('transaction.operationalShift')
            ->when($viewer->isVendor(), fn (Builder $query) => $query->whereHas('bucket.vendorItem', fn ($pair) => $pair->where('vendor_id', $viewer->vendor_id)))
            ->when($filters['vendor_id'] ?? null, fn (Builder $query, $value) => $query->whereHas('bucket.vendorItem', fn ($pair) => $pair->where('vendor_id', $value)))
            ->when($filters['item_id'] ?? null, fn (Builder $query, $value) => $query->whereHas('bucket', fn ($bucket) => $bucket->whereItem(fn ($item) => $item->whereKey($value))))
            ->when($filters['location_id'] ?? null, fn (Builder $query, $value) => $query->whereHas('bucket', fn ($bucket) => $bucket->where('location_id', $value)))
            ->when($filters['ownership'] ?? null, function (Builder $query, $value) {
                $query->whereHas('bucket', fn ($bucket) => $bucket->whereItem(fn ($item) => $item->where('ownership_type', $value)));
            })
            ->when($filters['type'] ?? null, function (Builder $query, $value) {
                $query->whereHas('transaction', fn ($transaction) => $transaction->where('type', $value));
            })
            ->when($filters['from'] ?? null, function (Builder $query, $value) {
                $query->whereHas('transaction', fn ($transaction) => $transaction->where('posted_at', '>=', $value));
            })
            ->when($filters['to'] ?? null, function (Builder $query, $value) {
                $query->whereHas('transaction', fn ($transaction) => $transaction->where('posted_at', '<=', $value));
            })
            ->when($filters['q'] ?? null, function (Builder $query, $value) {
                $term = '%'.str_replace(['%', '_'], ['\%', '\_'], trim($value)).'%';
                $query->where(fn ($nested) => $nested->whereHas('bucket', fn ($bucket) => $bucket->whereItem(fn ($item) => $item->where(fn ($match) => $match->where('item_code', 'like', $term)->orWhere('part_number', 'like', $term)->orWhere('item_name', 'like', $term))))->orWhereHas('transaction', fn ($transaction) => $transaction->where('transaction_no', 'like', $term)));
            })
            ->orderByDesc('recap_transactions.posted_at')
            ->orderByDesc('stock_ledger_entries.id');
    }

    /**
     * Work Order number and Backcharge flag for Data OUT ledger rows, keyed by ledger entry id.
     * Internal-only: Vendors never receive them.
     *
     * @param  Collection<int, StockLedgerEntry>  $entries
     * @return array<string, array{work_order_no: ?string, backcharge: bool}>
     */
    public function issueAnnotations(User $viewer, Collection $entries): array
    {
        if ($viewer->isVendor()) {
            return [];
        }
        $morph = (new MaterialIssue)->getMorphClass();
        $issueIds = $entries->filter(fn ($entry) => $entry->transaction->source_type === $morph)->map(fn ($entry) => $entry->transaction->source_id)->unique()->values();
        if ($issueIds->isEmpty()) {
            return [];
        }
        $issues = MaterialIssue::with('lines')->whereIn('id', $issueIds)->get()->keyBy('id');
        $notes = [];
        foreach ($entries as $entry) {
            $issue = $entry->transaction->source_type === $morph ? $issues->get($entry->transaction->source_id) : null;
            if ($issue === null) {
                continue;
            }
            $notes[$entry->id] = [
                'work_order_no' => $issue->work_order_no,
                'backcharge' => $issue->lines->contains(fn ($line) => $line->is_backcharge
                    && $line->vendor_item_id === $entry->bucket->vendor_item_id && $line->location_id === $entry->bucket->location_id),
            ];
        }

        return $notes;
    }

    public function summaries(User $viewer, array $filters): array
    {
        $rows = $this->build($viewer, $filters)->get();
        $in = 0;
        $out = 0;
        foreach ($rows as $row) {
            $units = $this->units($row->quantity);
            $units >= 0 ? $in += $units : $out += abs($units);
        }

        return ['rows' => $rows->count(), 'in' => $this->decimal($in), 'out' => $this->decimal($out), 'net' => $this->decimal($in - $out)];
    }

    private function units(string $quantity): int
    {
        $normalized = number_format(abs((float) $quantity), 4, '.', '');
        [$whole, $fraction] = explode('.', $normalized);
        $units = ((int) $whole * 10000) + (int) $fraction;

        return (float) $quantity < 0 ? -$units : $units;
    }

    private function decimal(int $units): string
    {
        $sign = $units < 0 ? '-' : '';
        $units = abs($units);

        return $sign.intdiv($units, 10000).'.'.str_pad((string) ($units % 10000), 4, '0', STR_PAD_LEFT);
    }
}
