<?php

namespace App\Services\Reconciliation;

use App\Models\User;
use App\Models\VendorReconciliation;
use App\Models\VendorReconciliationDocument;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class ReconciliationExcelExporter
{
    public function export(VendorReconciliation $ba, User $actor): VendorReconciliationDocument
    {
        if ($ba->status->value !== 'FINAL') {
            throw ValidationException::withMessages(['export' => 'Hanya BA final yang dapat diekspor.']);
        }
        if ($existing = $ba->documents()->where('type', 'XLSX')->where('version', $ba->version)->first()) {
            return $existing;
        }
        $ba->loadMissing(['vendor', 'lines.item', 'lines.location']);
        $sheet = (new Spreadsheet)->getActiveSheet();
        $sheet->fromArray(['BA', $ba->reconciliation_no, 'Vendor', $ba->vendor->vendor_name, 'Periode', $ba->period_start->format('Y-m-d').' - '.$ba->period_end->format('Y-m-d')], null, 'A1');
        $sheet->fromArray(['Item', 'PN', 'Lokasi', 'Opening', 'IN', 'OUT', 'Adjustment', 'Closing', 'Fisik', 'Variance'], null, 'A3');
        foreach ($ba->lines as $index => $line) {
            $sheet->fromArray([$this->safe($line->item->item_name), $this->safe($line->item->part_number), $this->safe($line->location->location_name), $line->opening_quantity, $line->in_quantity, $line->out_quantity, $line->adjustment_quantity, $line->system_closing_quantity, $line->physical_quantity, $line->variance_quantity], null, 'A'.($index + 4));
        }
        $temp = tempnam(sys_get_temp_dir(), 'ba-');
        (new Xlsx($sheet->getParent()))->save($temp);
        $binary = file_get_contents($temp);
        @unlink($temp);

        return $this->store($ba, $actor, 'XLSX', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'xlsx', $binary);
    }

    private function safe(?string $value): string
    {
        $value ??= '';

        return preg_match('/^[=+\-@]/', $value) ? "'{$value}" : $value;
    }

    private function store(VendorReconciliation $ba, User $actor, string $type, string $mime, string $extension, string $binary): VendorReconciliationDocument
    {
        $path = 'reconciliations/'.$ba->id.'/'.bin2hex(random_bytes(20)).'.'.$extension;
        Storage::disk('local')->put($path, $binary);

        return $ba->documents()->create(['version' => $ba->version, 'type' => $type, 'disk' => 'local', 'path' => $path, 'original_name' => $ba->reconciliation_no.'.'.$extension, 'mime_type' => $mime, 'size' => strlen($binary), 'checksum' => hash('sha256', $binary), 'created_by' => $actor->id]);
    }
}
