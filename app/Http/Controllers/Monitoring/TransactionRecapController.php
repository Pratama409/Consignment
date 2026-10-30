<?php

namespace App\Http\Controllers\Monitoring;

use App\Http\Controllers\Controller;
use App\Http\Requests\Monitoring\TransactionRecapRequest;
use App\Queries\TransactionRecapQuery;
use App\Services\Monitoring\TransactionRecapExporter;
use Inertia\Inertia;

class TransactionRecapController extends Controller
{
    public function index(TransactionRecapRequest $request, TransactionRecapQuery $query)
    {
        $filters = $request->validated();
        $rows = $query->build($request->user(), $filters)->paginate($filters['per_page'] ?? 25)->withQueryString();

        return Inertia::render('Monitoring/Transactions/Index', ['rows' => $rows->through(fn ($row) => $this->row($row)), 'summary' => $query->summaries($request->user(), $filters), 'filters' => $filters]);
    }

    public function export(TransactionRecapRequest $request, TransactionRecapQuery $query, TransactionRecapExporter $exporter)
    {
        $rows = $query->build($request->user(), $request->validated())->get();

        return response($exporter->binary($rows), 200, ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'Content-Disposition' => 'attachment; filename="rekap-transaksi.xlsx"']);
    }

    private function row($row): array
    {
        return ['id' => $row->id, 'transaction_no' => $row->transaction->transaction_no, 'type' => $row->transaction->type->value, 'posted_at' => $row->transaction->posted_at?->timezone('Asia/Makassar')->format('d/m/Y H:i'), 'physical_at' => $row->physical_occurred_at->timezone('Asia/Makassar')->format('d/m/Y H:i'), 'shift' => $row->transaction->operationalShift?->code, 'late' => $row->transaction->is_late_posting, 'item' => $row->item->item_code.' · '.$row->item->item_name, 'ownership' => $row->item->ownership_type->value, 'vendor' => $row->vendor?->vendor_name ?? 'WAREHOUSE', 'location' => $row->location->location_name, 'quantity' => $row->quantity];
    }
}
