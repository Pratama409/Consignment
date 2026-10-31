<?php

namespace App\Http\Controllers\Monitoring;

use App\Http\Controllers\Controller;
use App\Http\Requests\Monitoring\SystemStockRequest;
use App\Models\StockBalance;
use App\Models\StockBucket;
use App\Models\StockLedgerEntry;
use App\Models\StockLot;
use App\Models\StockOpnameLine;
use App\Queries\SystemStockQuery;
use Inertia\Inertia;

class SystemStockController extends Controller
{
    public function index(SystemStockRequest $request, SystemStockQuery $query)
    {
        $filters = $request->validated();
        $rows = $query->build($request->user(), $filters)->paginate($filters['per_page'] ?? 25)->withQueryString();

        $blind = $this->blindBucketIds($request);

        return Inertia::render('Monitoring/SystemStock/Index', ['rows' => $rows->through(fn ($row) => $this->row($row, $blind)), 'filters' => $filters]);
    }

    public function show(SystemStockRequest $request, StockBalance $balance)
    {
        $balance->load(array_map(fn (string $relation) => "bucket.{$relation}", StockBucket::IDENTITY));
        abort_if($request->user()->isVendor() && $balance->vendor?->id !== $request->user()->vendor_id, 403);
        abort_if(in_array($balance->stock_bucket_id, $this->blindBucketIds($request), true), 403, 'Barang sedang dihitung dalam Stock Opname.');

        return Inertia::render('Monitoring/SystemStock/Show', ['balance' => $this->row($balance), 'ledger' => StockLedgerEntry::with('transaction')->where('stock_bucket_id', $balance->stock_bucket_id)->latest()->paginate(25), 'lots' => StockLot::where('stock_bucket_id', $balance->stock_bucket_id)->orderBy('received_at')->get()]);
    }

    /** Buckets a Warehouse user is counting right now must not reveal their system quantity. */
    private function blindBucketIds(SystemStockRequest $request): array
    {
        if (! $request->user()->isWarehouse()) {
            return [];
        }

        return StockOpnameLine::whereHas('session', fn ($session) => $session->where('status', 'COUNTING'))->pluck('stock_bucket_id')->all();
    }

    private function row($row, array $blind = []): array
    {
        return ['id' => $row->id, 'item' => $row->item->item_code.' · '.$row->item->item_name, 'part_number' => $row->item->part_number, 'ownership' => $row->item->ownership_type->value, 'vendor' => $row->vendorItem?->vendor?->vendor_name ?? 'WAREHOUSE', 'location' => $row->location->location_name, 'quantity' => in_array($row->stock_bucket_id, $blind, true) ? null : $row->quantity, 'counting' => in_array($row->stock_bucket_id, $blind, true), 'unit' => $row->item->unit->code];
    }
}
