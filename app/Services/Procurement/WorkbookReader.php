<?php

namespace App\Services\Procurement;

use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\IOFactory;

class WorkbookReader
{
    public function read(UploadedFile $file): array
    {
        $sheet = IOFactory::load($file->getRealPath())->getActiveSheet();
        $rows = [];
        foreach ($sheet->toArray(null, true, true, true) as $i => $r) {
            if ($i === 1) {
                continue;
            }if (str_starts_with((string) ($r['F'] ?? ''), '=')) {
                throw ValidationException::withMessages(['file' => "Formula tidak diizinkan pada baris {$i}."]);
            }if (trim((string) ($r['A'] ?? '')) === '') {
                continue;
            }$rows[] = ['row_key' => (string) $r['A'], 'document_no' => (string) $r['F'], 'quantity' => (string) $r['G'], 'row' => $i];
        }

        return $rows;
    }
}
