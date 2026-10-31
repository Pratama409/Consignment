<?php

namespace App\Services\MasterData;

use Illuminate\Database\Eloquent\Builder;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class WorkbookExporter
{
    public function download(?Builder $query = null, bool $template = false, ?string $vendorId = null)
    {
        $book = new Spreadsheet;
        $sheet = $book->getActiveSheet()->setTitle('Master PN');
        foreach (WorkbookReader::COLUMNS as $index => $header) {
            $sheet->setCellValueExplicit([$index + 1, 1], $header, DataType::TYPE_STRING);
        }
        $number = 2;
        if ($query) {
            foreach ($query->with(['category', 'unit', 'vendorItems' => fn ($query) => $query->when($vendorId, fn ($query) => $query->active()->where('vendor_id', $vendorId))->with(['vendor', 'location']), 'stockItemLocations.location'])->lazy(200) as $item) {
                $relations = $item->ownership_type->value === 'CONSIGNMENT' ? $item->vendorItems : $item->stockItemLocations;
                foreach ($relations->isEmpty() ? [null] : $relations as $relation) {
                    $values = [$item->item_code, $item->ownership_type->value === 'STOCK' ? 'WAREHOUSE' : $relation?->vendor?->vendor_code,
                        $item->part_number, $item->item_name, $relation?->location?->location_code, $item->unit->code, $item->ownership_type->value,
                        $item->minimum_stock, $item->is_active ? '1' : '0', $item->tracking_type->value, $relation ? ($relation->is_active ? '1' : '0') : '', $item->category?->code];
                    foreach ($values as $index => $value) {
                        $sheet->setCellValueExplicit([$index + 1, $number], (string) ($value ?? ''), DataType::TYPE_STRING);
                    }
                    $number++;
                }
            }
        }
        $lastColumn = Coordinate::stringFromColumnIndex(count(WorkbookReader::COLUMNS));
        $sheet->getStyle('A1:'.$lastColumn.'1')->getFont()->setBold(true)->getColor()->setARGB('FFFFFFFF');
        $sheet->getStyle('A1:'.$lastColumn.'1')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FF111827');
        $sheet->freezePane('A2')->setAutoFilter('A1:'.$lastColumn.max(1, $number - 1));
        foreach (range('A', $lastColumn) as $column) {
            $sheet->getColumnDimension($column)->setWidth($column === 'D' ? 45 : 22);
        }
        if ($template) {
            $guide = $book->createSheet()->setTitle('Petunjuk');
            foreach (['Isi data pada sheet pertama; header tidak diubah.', 'Kosongkan ITEM_CODE untuk barang baru. Gunakan kode dari export untuk memperbarui barang.', 'Satu baris mewakili satu pasangan barang–Vendor atau satu lokasi STOCK.', 'Minimum adalah target refill, angka nonnegatif maksimal empat desimal.', 'IS_ACTIVE dan RELATION_ACTIVE menggunakan 1 atau 0.', 'WAREHOUSE pada STOCK bukan Vendor. Lokasi belum diketahui dapat memakai UNASSIGNED.', 'Import selalu melalui preview; relasi yang tidak dicantumkan tetap dipertahankan.'] as $index => $message) {
                $guide->setCellValueExplicit([1, $index + 1], $message, DataType::TYPE_STRING);
            }
            $guide->getColumnDimension('A')->setWidth(110);
            $guide->setCellValueExplicit('A8', 'CATEGORY_CODE opsional: gunakan kode Master Kategori. Kosong mempertahankan kategori barang lama; barang baru tetap belum dikategorikan. Perubahan ke tanpa kategori dilakukan melalui form Barang.', DataType::TYPE_STRING);
        }
        $book->setActiveSheetIndex(0);

        return response()->streamDownload(function () use ($book) {
            $writer = new Xlsx($book);
            $writer->setPreCalculateFormulas(false);
            $writer->save('php://output');
            $book->disconnectWorksheets();
        }, $template ? 'template-master-pn.xlsx' : 'master-pn-'.now()->format('Ymd-His').'.xlsx', ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']);
    }
}
