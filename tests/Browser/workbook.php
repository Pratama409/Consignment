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
foreach ([$headers, ...$rows] as $rowIndex => $row) {
    foreach ($row as $column => $value) {
        $book->getActiveSheet()->setCellValueExplicit([$column + 1, $rowIndex + 1], $value, DataType::TYPE_STRING);
    }
}
$writer = new Xlsx($book);
$writer->save('php://output');
