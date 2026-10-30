<?php

namespace App\Services\Monitoring;

use Illuminate\Support\Collection;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class TransactionRecapExporter
{
    public function binary(Collection $rows): string
    {
        $sheet = (new Spreadsheet)->getActiveSheet();
        $sheet->fromArray(['Transaksi', 'Posted WITA', 'Fisik', 'Shift', 'Tipe', 'Item', 'PN', 'Ownership', 'Vendor', 'Lokasi', 'Quantity', 'Late'], null, 'A1');
        foreach ($rows->values() as $index => $row) {
            $sheet->fromArray([$this->safe($row->transaction->transaction_no), $row->transaction->posted_at?->timezone('Asia/Makassar')->format('Y-m-d H:i:s'), $row->physical_occurred_at->timezone('Asia/Makassar')->format('Y-m-d H:i:s'), $row->transaction->operationalShift?->code, $row->transaction->type->value, $this->safe($row->item->item_code), $this->safe($row->item->part_number), $row->item->ownership_type->value, $this->safe($row->vendor?->vendor_name ?? 'WAREHOUSE'), $this->safe($row->location->location_name), $row->quantity, $row->transaction->is_late_posting ? 'YA' : 'TIDAK'], null, 'A'.($index + 2));
        }
        $path = tempnam(sys_get_temp_dir(), 'recap-');
        (new Xlsx($sheet->getParent()))->save($path);
        $binary = file_get_contents($path);
        @unlink($path);

        return $binary;
    }

    private function safe(?string $value): string
    {
        $value ??= '';

        return preg_match('/^[=+\-@]/', $value) ? "'{$value}" : $value;
    }
}
