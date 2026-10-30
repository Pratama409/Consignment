<?php

namespace App\Http\Controllers\Monitoring;

use App\Http\Controllers\Controller;
use App\Http\Requests\Monitoring\SystemStockRequest;
use App\Models\StockBalance;
use App\Models\StockLedgerEntry;
use App\Models\StockLot;
use App\Queries\SystemStockQuery;
use Inertia\Inertia;

class SystemStockController extends Controller
{
    public function index(SystemStockRequest $request, SystemStockQuery $query)
    {
        $filters = $request->validated();
        $rows = $query->build($request->user(), $filters)->paginate($filters['per_page'] ?? 25)->withQueryString();

        return Inertia::render('Monitoring/SystemStock/Index', ['rows' => $rows->through(fn ($row) => $this->row($row)), 'filters' => $filters]);
    }

    public function show(SystemStockRequest $request, StockBalance $balance)
    {
        abort_if($request->user()->isVendor() && $balance->vendorItem?->vendor_id !== $request->user()->vendor_id, 403);
        $balance->load(['item.unit', 'vendorItem.vendor', 'location']);

        return Inertia::render('Monitoring/SystemStock/Show', ['balance' => $this->row($balance), 'ledger' => StockLedgerEntry::with('transaction')->where('bucket_key', $balance->bucket_key)->latest()->paginate(25), 'lots' => StockLot::where('bucket_key', $balance->bucket_key)->orderBy('received_at')->get()]);
    }

    private function row($row): array
    {
        return ['id' => $row->id, 'item' => $row->item->item_code.' · '.$row->item->item_name, 'part_number' => $row->item->part_number, 'ownership' => $row->item->ownership_type->value, 'vendor' => $row->vendorItem?->vendor?->vendor_name ?? 'WAREHOUSE', 'location' => $row->location->location_name, 'quantity' => $row->quantity, 'unit' => $row->item->unit->code];
    }
}
