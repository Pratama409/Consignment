<?php

namespace App\Services\MasterData;

use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Reader\IReadFilter;
use PhpOffice\PhpSpreadsheet\Reader\Xlsx;
use Throwable;
use ZipArchive;

class WorkbookReader
{
    public const COLUMNS = ['ITEM_CODE', 'VENDOR', 'PART_NUMBER', 'DESCRIPTION', 'LOCATION', 'UOM', 'MATERIAL_TYPE', 'MINIMUM_STOCK', 'IS_ACTIVE', 'TRACKING_TYPE', 'RELATION_ACTIVE'];

    public function read(UploadedFile $file): array
    {
        $zip = new ZipArchive;
        if ($zip->open($file->getRealPath()) !== true) {
            $this->fail('File harus berupa workbook XLSX yang valid.');
        }
        $expanded = 0;
        for ($index = 0; $index < $zip->numFiles; $index++) {
            $expanded += $zip->statIndex($index)['size'];
        }
        $zip->close();
        if ($expanded > 32 * 1024 * 1024) {
            $this->fail('Isi workbook terlalu besar. Batas isi terurai adalah 32 MB.');
        }

        try {
            $reader = new Xlsx;
            $info = $reader->listWorksheetInfo($file->getRealPath());
            if (($info[0]['totalRows'] ?? 0) > 5001 || ($info[0]['totalColumns'] ?? 0) > 30) {
                $this->fail('Batas import adalah 5.000 baris data dan 30 kolom pada sheet pertama.');
            }
            $reader->setLoadSheetsOnly($info[0]['worksheetName']);
            $reader->setReadDataOnly(true);
            $reader->setReadFilter(new class implements IReadFilter
            {
                public function readCell(string $columnAddress, int $row, string $worksheetName = ''): bool
                {
                    return $row <= 5001;
                }
            });
            $workbook = $reader->load($file->getRealPath());
            $sheet = $workbook->getSheet(0);
            $headers = [];
            for ($column = 1; $column <= min(30, $info[0]['totalColumns']); $column++) {
                $value = $sheet->getCell([$column, 1])->getValue();
                $header = mb_strtoupper(str_replace(' ', '_', trim((string) $value, " \t\n\r\0\x0B\xEF\xBB\xBF")));
                if (in_array($header, self::COLUMNS, true)) {
                    if (in_array($header, $headers, true)) {
                        $this->fail('Header '.$header.' muncul lebih dari sekali.');
                    }
                    $headers[$column] = $header;
                }
            }
            $missing = array_diff(['VENDOR', 'PART_NUMBER', 'DESCRIPTION', 'LOCATION', 'UOM', 'MATERIAL_TYPE'], $headers);
            if ($missing) {
                $this->fail('Kolom wajib tidak ditemukan: '.implode(', ', $missing).'. Gunakan template yang tersedia.');
            }
            $rows = [];
            for ($number = 2; $number <= $sheet->getHighestDataRow(); $number++) {
                $values = [];
                $issues = [];
                foreach ($headers as $column => $header) {
                    $cell = $sheet->getCell([$column, $number]);
                    $value = $cell->getValue();
                    $values[$header] = trim((string) ($value ?? ''));
                    if ($cell->getDataType() === DataType::TYPE_FORMULA) {
                        $issues[$header] = 'Formula tidak dibaca sebagai data. Ganti dengan nilai teks atau angka yang sebenarnya.';
                    }
                    if ($header === 'LOCATION' && is_float($value)) {
                        $issues[$header] = 'Lokasi tersimpan sebagai angka eksponensial. Verifikasi kode lokasi aslinya.';
                    }
                    if ($header === 'PART_NUMBER' && is_float($value) && (str_contains(strtoupper((string) $value), 'E') || $value !== floor($value))) {
                        $issues[$header] = 'PN numerik tidak dapat dipastikan. Gunakan teks PN yang sebenarnya.';
                    }
                }
                if (collect($values)->every(fn ($value) => $value === '')) {
                    continue;
                }
                $rows[] = ['number' => $number, 'values' => $values, 'issues' => $issues];
            }
            $workbook->disconnectWorksheets();
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (Throwable) {
            $this->fail('Workbook tidak dapat dibaca. Pastikan file XLSX tidak rusak atau dilindungi kata sandi.');
        }
        if (! $rows) {
            $this->fail('File tidak memiliki baris data.');
        }

        return ['headers' => array_values($headers), 'rows' => $rows];
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['file' => $message]);
    }
}
