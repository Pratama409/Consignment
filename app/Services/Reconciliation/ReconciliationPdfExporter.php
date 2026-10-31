<?php

namespace App\Services\Reconciliation;

use App\Models\User;
use App\Models\VendorReconciliation;
use App\Models\VendorReconciliationDocument;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class ReconciliationPdfExporter
{
    public function export(VendorReconciliation $ba, User $actor): VendorReconciliationDocument
    {
        if ($ba->status->value !== 'FINAL') {
            throw ValidationException::withMessages(['export' => 'Hanya BA final yang dapat diekspor.']);
        }
        if ($existing = $ba->documents()->where('type', 'PDF')->where('version', $ba->version)->first()) {
            return $existing;
        }
        $ba->loadMissing(['vendor', 'lines.item', 'lines.location']);
        $lines = ["BA {$ba->reconciliation_no}", "Vendor {$ba->vendor->vendor_name}", "Periode {$ba->period_start->format('Y-m-d')} - {$ba->period_end->format('Y-m-d')}"];
        foreach ($ba->lines as $line) {
            $lines[] = "{$line->item->part_number} | {$line->location->location_name} | {$line->opening_quantity} + {$line->in_quantity} - {$line->out_quantity} = {$line->system_closing_quantity}";
        }
        $binary = $this->pdf($lines);
        $path = 'reconciliations/'.$ba->id.'/'.bin2hex(random_bytes(20)).'.pdf';
        Storage::disk('local')->put($path, $binary);

        return $ba->documents()->create(['version' => $ba->version, 'type' => 'PDF', 'disk' => 'local', 'path' => $path, 'original_name' => $ba->reconciliation_no.'.pdf', 'mime_type' => 'application/pdf', 'size' => strlen($binary), 'checksum' => hash('sha256', $binary), 'created_by' => $actor->id]);
    }

    private function pdf(array $lines): string
    {
        $text = 'BT /F1 10 Tf 40 800 Td ';
        foreach ($lines as $index => $line) {
            $escaped = str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $line);
            $text .= ($index ? '0 -16 Td ' : '')."({$escaped}) Tj ";
        } $text .= 'ET';
        $objects = ["1 0 obj << /Type /Catalog /Pages 2 0 R >> endobj\n", "2 0 obj << /Type /Pages /Kids [3 0 R] /Count 1 >> endobj\n", "3 0 obj << /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 4 0 R >> >> /Contents 5 0 R >> endobj\n", "4 0 obj << /Type /Font /Subtype /Type1 /BaseFont /Helvetica >> endobj\n", '5 0 obj << /Length '.strlen($text)." >> stream\n{$text}\nendstream endobj\n"];
        $pdf = "%PDF-1.4\n";
        $offsets = [0];
        foreach ($objects as $object) {
            $offsets[] = strlen($pdf);
            $pdf .= $object;
        } $xref = strlen($pdf);
        $pdf .= "xref\n0 6\n0000000000 65535 f \n";
        for ($i = 1; $i <= 5; $i++) {
            $pdf .= sprintf("%010d 00000 n \n", $offsets[$i]);
        }

        return $pdf."trailer << /Size 6 /Root 1 0 R >>\nstartxref\n{$xref}\n%%EOF";
    }
}
