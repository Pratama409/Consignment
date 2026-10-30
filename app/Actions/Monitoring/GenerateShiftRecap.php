<?php

namespace App\Actions\Monitoring;

use App\Models\MaterialIssueLine;
use App\Models\OperationalShift;
use App\Models\StockLedgerEntry;
use App\Models\VendorRefill;
use Illuminate\Support\Facades\Schema;

class GenerateShiftRecap
{
    public function handle(OperationalShift $shift): array
    {
        $rows = StockLedgerEntry::query()->whereHas('transaction', fn ($query) => $query->where('operational_shift_id', $shift->id))->with('transaction')->orderBy('id')->get();
        $opening = $this->sumUnits(StockLedgerEntry::query()->whereHas('transaction', fn ($query) => $query->where('posted_at', '<', $shift->starts_at))->pluck('quantity')->all());
        $incoming = $this->sumUnits($rows->filter(fn ($row) => in_array($row->transaction->type->value, ['OPENING', 'REFILL_RECEIPT', 'MANUAL_RECEIPT'], true))->pluck('quantity')->all());
        $out = abs($this->sumUnits($rows->filter(fn ($row) => $row->transaction->type->value === 'MATERIAL_ISSUE')->pluck('quantity')->all()));
        $adjustment = $this->sum($rows->filter(fn ($row) => in_array($row->transaction->type->value, ['ADJUSTMENT', 'REVERSAL'], true))->pluck('quantity')->all());
        $outstandingDocuments = Schema::hasColumn('material_issue_lines', 'documents_outstanding') ? (int) MaterialIssueLine::where('documents_outstanding', true)->count() : 0;
        $summary = [
            'opening' => $this->decimal($opening), 'in' => $this->decimal($incoming),
            'out' => $this->decimal($out), 'adjustment' => $adjustment,
            'closing' => $this->decimal($opening + $this->sumUnits($rows->pluck('quantity')->all())),
            'outstanding_refills' => VendorRefill::whereNotIn('status', ['COMPLETED', 'CLOSED_PARTIAL', 'CANCELLED'])->count(),
            'outstanding_documents' => $outstandingDocuments,
            'late_postings' => $rows->where('transaction.is_late_posting', true)->pluck('stock_transaction_id')->unique()->sort()->values()->all(),
            'transaction_ids' => $rows->pluck('stock_transaction_id')->unique()->sort()->values()->all(),
        ];

        return $summary;
    }

    private function sum(array $values): string
    {
        return $this->decimal($this->sumUnits($values));
    }

    private function sumUnits(iterable $values): int
    {
        $sum = 0;
        foreach ($values as $value) {
            $normalized = number_format((float) $value, 4, '.', '');
            [$whole, $fraction] = explode('.', ltrim($normalized, '-'));
            $units = ((int) $whole * 10000) + (int) $fraction;
            $sum += str_starts_with($normalized, '-') ? -$units : $units;
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
