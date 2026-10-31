<?php

namespace App\Services\Procurement;

use PhpOffice\PhpSpreadsheet\Spreadsheet;

class WorkbookExporter
{
    public function export(iterable $requirements): Spreadsheet
    {
        $s = new Spreadsheet;
        $sheet = $s->getActiveSheet();
        $sheet->fromArray(['ROW_KEY', 'VENDOR', 'PART_NUMBER', 'ITEM', 'ISSUED_QTY', 'DOCUMENT_NO', 'DOCUMENT_QTY'], null, 'A1');
        $row = 2;
        foreach ($requirements as $r) {
            $sheet->fromArray([$r->row_key, $r->vendorItem->vendor->vendor_name, $r->issueLine->requestLine->item->part_number, $r->issueLine->requestLine->item->item_name, $r->requiredQuantity(), '', ''], null, "A{$row}");
            $row++;
        }

        return $s;
    }
}
