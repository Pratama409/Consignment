<?php

namespace App\Services\OpeningBalance;

use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\IOFactory;

class WorkbookReader
{
    private const COLUMNS = ['ITEM_CODE', 'LOCATION_CODE', 'VENDOR_CODE', 'QUANTITY'];

    public function read(UploadedFile $file): array
    {
        $sheet = IOFactory::load($file->getRealPath())->getActiveSheet();
        $highestRow = $sheet->getHighestDataRow();
        $highestColumn = $sheet->getHighestDataColumn();
        $headerValues = $sheet->rangeToArray("A1:{$highestColumn}1", null, true, false)[0] ?? [];
        $headers = [];
        foreach ($headerValues as $index => $value) {
            $name = mb_strtoupper(trim((string) $value));
            if ($name !== '') {
                $headers[$name] = $index;
            }
        }
        $missing = array_values(array_diff(self::COLUMNS, array_keys($headers)));
        if ($missing !== []) {
            throw ValidationException::withMessages(['file' => 'Kolom wajib tidak ditemukan: '.implode(', ', $missing).'.']);
        }

        $rows = [];
        for ($number = 2; $number <= $highestRow; $number++) {
            $values = $sheet->rangeToArray("A{$number}:{$highestColumn}{$number}", null, true, false)[0] ?? [];
            $row = ['row_number' => $number];
            foreach (self::COLUMNS as $column) {
                $row[strtolower($column)] = trim((string) ($values[$headers[$column]] ?? ''));
            }
            if (collect($row)->except('row_number')->filter(fn ($value) => $value !== '')->isNotEmpty()) {
                $rows[] = $row;
            }
        }

        return $rows;
    }
}
