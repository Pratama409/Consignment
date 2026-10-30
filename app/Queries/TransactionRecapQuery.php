<?php

namespace App\Queries;

use App\Models\StockLedgerEntry;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

class TransactionRecapQuery
{
    public function build(User $viewer, array $filters): Builder
    {
        return StockLedgerEntry::query()
            ->select('stock_ledger_entries.*')
            ->join('stock_transactions as recap_transactions', 'recap_transactions.id', '=', 'stock_ledger_entries.stock_transaction_id')
            ->with(['transaction.operationalShift', 'item', 'vendor', 'location'])
            ->when($viewer->isVendor(), fn (Builder $query) => $query->where('vendor_id', $viewer->vendor_id))
            ->when($filters['vendor_id'] ?? null, fn (Builder $query, $value) => $query->where('vendor_id', $value))
            ->when($filters['item_id'] ?? null, fn (Builder $query, $value) => $query->where('item_id', $value))
            ->when($filters['location_id'] ?? null, fn (Builder $query, $value) => $query->where('location_id', $value))
            ->when($filters['ownership'] ?? null, function (Builder $query, $value) {
                $query->whereHas('item', fn ($item) => $item->where('ownership_type', $value));
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
                $query->where(fn ($nested) => $nested->whereHas('item', fn ($item) => $item->where('item_code', 'like', $term)->orWhere('part_number', 'like', $term)->orWhere('item_name', 'like', $term))->orWhereHas('transaction', fn ($transaction) => $transaction->where('transaction_no', 'like', $term)));
            })
            ->orderByDesc('recap_transactions.posted_at')
            ->orderByDesc('stock_ledger_entries.id');
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
        [$whole, $fraction] = explode('.', number_format((float) $quantity, 4, '.', ''));

        return ((int) $whole * 10000) + ((int) $whole < 0 ? -(int) $fraction : (int) $fraction);
    }

    private function decimal(int $units): string
    {
        $sign = $units < 0 ? '-' : '';
        $units = abs($units);

        return $sign.intdiv($units, 10000).'.'.str_pad((string) ($units % 10000), 4, '0', STR_PAD_LEFT);
    }
}
