<?php

namespace App\Services\Refills;

use App\Models\VendorItem;
use App\Models\VendorRefillLine;

class RefillSuggestion
{
    public function for(VendorItem $relation): string
    {
        $target = $this->units($relation->item()->value('minimum_stock'));
        $stock = $relation->stockBalances()->sum('quantity');
        $stockUnits = $this->units((string) $stock);
        $open = VendorRefillLine::query()
            ->where('item_id', $relation->item_id)
            ->whereHas('refill', fn ($query) => $query->where('vendor_id', $relation->vendor_id)->whereNotIn('status', ['COMPLETED', 'CLOSED_PARTIAL', 'CANCELLED']))
            ->withSum(['receiptLines as posted_received' => fn ($query) => $query->whereHas('receipt', fn ($receipt) => $receipt->where('status', 'POSTED'))], 'received_quantity')
            ->get()
            ->sum(fn (VendorRefillLine $line) => max(0, $this->units($line->requested_quantity) - $this->units($line->posted_received ?? '0')));

        return (string) intdiv(max(0, $target - $stockUnits - $open) + 9999, 10000);
    }

    private function units(string|int|float|null $value): int
    {
        $value = number_format((float) ($value ?? 0), 4, '.', '');
        [$whole, $fraction] = explode('.', $value);

        return ((int) $whole * 10000) + (int) $fraction;
    }
}
