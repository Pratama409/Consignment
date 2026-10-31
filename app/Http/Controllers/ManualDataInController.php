<?php

namespace App\Http\Controllers;

use App\Actions\Receipts\PostManualReceipt;
use App\Models\Item;
use App\Models\ManualReceipt;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;

class ManualDataInController extends Controller
{
    public function index(Request $request)
    {
        abort_if($request->user()->isVendor(), 403);
        $items = Item::active()->with(['unit', 'vendorItems' => fn ($query) => $query->active()->with(['vendor', 'location']), 'stockItemLocations' => fn ($query) => $query->active()->with('location')])->orderBy('item_code')->get();

        return Inertia::render('Receipts/ManualDataIn', [
            'can_post' => $request->user()->isWarehouse(),
            'store_url' => url('/manual-data-in'),
            'idempotency_key' => (string) Str::uuid(),
            'items' => $items->map(fn ($item) => ['id' => $item->id, 'code' => $item->item_code, 'name' => $item->item_name, 'ownership' => $item->ownership_type->value, 'unit' => $item->unit->code, 'buckets' => $item->ownership_type->value === 'CONSIGNMENT' ? $item->vendorItems->map(fn ($relation) => ['vendor_item_id' => $relation->id, 'location_id' => $relation->location_id, 'label' => $relation->vendor->vendor_name.' · '.$relation->location->location_name])->values() : $item->stockItemLocations->map(fn ($placement) => ['vendor_item_id' => null, 'location_id' => $placement->location_id, 'label' => $placement->location->location_name])->values()]),
            'history' => ManualReceipt::with(['receiver', 'lines'])->latest('received_at')->take(20)->get()->map(fn ($receipt) => ['number' => $receipt->receipt_no, 'received_at' => $receipt->received_at->timezone('Asia/Makassar')->format('d/m/Y H:i'), 'receiver' => $receipt->receiver_name, 'posted_by' => $receipt->receiver->name, 'do_number' => $receipt->vendor_do_number, 'status' => $receipt->status, 'line_count' => $receipt->lines->count()]),
        ]);
    }

    public function store(Request $request, PostManualReceipt $action)
    {
        abort_unless($request->user()->isWarehouse(), 403);
        $data = $request->validate(['reason' => ['required', 'string', 'max:2000'], 'vendor_do_number' => ['nullable', 'string', 'max:100'], 'receiver_name' => ['required', 'string', 'max:150'], 'received_at' => ['required', 'date'], 'idempotency_key' => ['required', 'string', 'max:100'], 'lines' => ['required', 'array', 'min:1'], 'lines.*.item_id' => ['required', 'uuid'], 'lines.*.vendor_item_id' => ['nullable', 'uuid'], 'lines.*.location_id' => ['required', 'uuid'], 'lines.*.quantity' => ['required', 'integer', 'min:1']]);
        $action->handle($request->user(), $data['reason'], $data['vendor_do_number'] ?? null, $data['receiver_name'], CarbonImmutable::parse($data['received_at'], 'Asia/Makassar'), $data['lines'], $data['idempotency_key']);

        return back()->with('success', 'Manual Data IN berhasil diposting.');
    }
}
