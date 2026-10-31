<?php

namespace App\Actions\Procurement;

use App\Models\ProcurementRequirement;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ApplyProcurementImport
{
    public function __construct(private SaveProcurementReference $save) {}

    public function handle(string $stage, array $rows): void
    {
        DB::transaction(function () use ($stage, $rows) {
            foreach (collect($rows)->groupBy('row_key') as $group) {
                $row = $group->first();
                $r = ProcurementRequirement::where('row_key', $row['row_key'])->lockForUpdate()->first();
                if (! $r) {
                    throw ValidationException::withMessages(['file' => "Row key baris {$row['row']} tidak valid."]);
                }
                $this->save->handle($r, $stage, $group->map(fn ($line) => ['document_no' => $line['document_no'], 'quantity' => $line['quantity']])->values()->all());
            }
        });
    }
}
