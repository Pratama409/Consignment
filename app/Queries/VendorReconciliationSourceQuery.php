<?php

namespace App\Queries;

use App\Models\StockLedgerEntry;
use App\Models\Vendor;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

class VendorReconciliationSourceQuery
{
    public function forPeriod(Vendor $vendor, CarbonImmutable $start, CarbonImmutable $end): Collection
    {
        $startUtc = $start->startOfDay('Asia/Makassar')->utc();
        $endUtc = $end->endOfDay('Asia/Makassar')->utc();
        $rows = StockLedgerEntry::query()->with(['transaction', 'bucket.vendorItem'])->whereHas('bucket.vendorItem', fn ($pair) => $pair->where('vendor_id', $vendor->id))->whereHas('transaction', fn ($query) => $query->where('posted_at', '<=', $endUtc))->get()->groupBy('stock_bucket_id');

        return $rows->map(function (Collection $entries) use ($startUtc, $endUtc): array {
            $first = $entries->first();
            $opening = $this->sum($entries->filter(fn ($row) => $row->transaction->posted_at->lt($startUtc))->pluck('quantity'));
            $period = $entries->filter(fn ($row) => $row->transaction->posted_at->betweenIncluded($startUtc, $endUtc));
            $incoming = $this->sum($period->filter(fn ($row) => in_array($row->transaction->type->value, ['OPENING', 'REFILL_RECEIPT', 'MANUAL_RECEIPT'], true))->pluck('quantity'));
            $out = abs($this->sum($period->filter(fn ($row) => $row->transaction->type->value === 'MATERIAL_ISSUE')->pluck('quantity')));
            $adjustment = $this->sum($period->filter(fn ($row) => in_array($row->transaction->type->value, ['ADJUSTMENT', 'REVERSAL'], true))->pluck('quantity'));

            return ['vendor_item_id' => $first->bucket->vendor_item_id, 'item_id' => $first->bucket->vendorItem->item_id, 'location_id' => $first->bucket->location_id, 'opening' => $this->decimal($opening), 'in' => $this->decimal($incoming), 'out' => $this->decimal($out), 'adjustment' => $this->decimal($adjustment), 'closing' => $this->decimal($opening + $incoming - $out + $adjustment), 'transaction_ids' => $period->pluck('stock_transaction_id')->unique()->sort()->values()->all()];
        })->values();
    }

    private function sum(iterable $values): int
    {
        $sum = 0;
        foreach ($values as $value) {
            $normalized = number_format((float) $value, 4, '.', '');
            $negative = str_starts_with($normalized, '-');
            [$whole, $fraction] = explode('.', ltrim($normalized, '-'));
            $units = ((int) $whole * 10000) + (int) $fraction;
            $sum += $negative ? -$units : $units;
        }

        return $sum;
    }

    private function decimal(int $units): string
    {
        $sign = $units < 0 ? '-' : '';
        $units = abs($units);

        return $sign.intdiv($units, 10000).'.'.str_pad((string) ($units % 10000), 4, '0', STR_PAD_LEFT);
    }
}
