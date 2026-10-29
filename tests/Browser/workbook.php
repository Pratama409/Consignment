<?php

// Generate a workbook in memory for isolated browser tests only.
require __DIR__.'/../../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

$book = new Spreadsheet;
$headers = ['VENDOR', 'PART_NUMBER', 'DESCRIPTION', 'LOCATION', 'UOM', 'MATERIAL_TYPE'];
$rows = [
    ['WAREHOUSE', '000BROWSER.', 'Bearing import browser', 'IMP-A', 'PC', 'STOCK'],
    ['WAREHOUSE', '000BROWSER.', 'Bearing import browser', 'IMP-B', 'PC', 'STOCK'],
    ['WAREHOUSE', '000BROWSER.', 'Bearing import browser', 'IMP-A', 'PC', 'STOCK'],
    ['WAREHOUSE', 'CORRECT-BROWSER', 'Perbaikan lokasi browser', 'A & B', 'PC', 'STOCK'],
];
if (($argv[1] ?? '') === 'category') {
    $headers = [...$headers, 'CATEGORY_CODE', 'LEGACY_PRICE'];
    $rows = [
        ['WAREHOUSE', 'CAT-BROWSER', 'Kategori dari import', 'UNASSIGNED', 'PC', 'STOCK', 'Kategori belum dikenali', '15000'],
        ['WAREHOUSE', 'UNCAT-BROWSER', 'Barang tanpa kategori import', 'UNASSIGNED', 'PC', 'STOCK', '', '25000'],
    ];
}
foreach ([$headers, ...$rows] as $rowIndex => $row) {
    foreach ($row as $column => $value) {
        $book->getActiveSheet()->setCellValueExplicit([$column + 1, $rowIndex + 1], $value, DataType::TYPE_STRING);
    }
}
$writer = new Xlsx($book);
$writer->save('php://output');
