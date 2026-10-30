<?php

namespace App\Http\Controllers;

use App\Actions\Refills\PostRefillReceipt;
use App\Models\Location;
use App\Models\VendorRefillShipment;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;

class IncomingRefillController extends Controller
{
    public function index(Request $request)
    {
        abort_if($request->user()->isVendor(), 403);

        return Inertia::render('Refills/Incoming', ['shipments' => VendorRefillShipment::whereIn('status', ['DISPATCHED', 'PARTIALLY_RECEIVED'])->with(['refill.vendor', 'lines.refillLine.item'])->get()->map(fn ($s) => ['id' => $s->id, 'refill' => $s->refill->refill_no, 'vendor' => $s->refill->vendor->vendor_name, 'document_no' => $s->vendor_document_no, 'status' => $s->status, 'receipt_url' => route('incoming-refills.receipts.store', $s), 'lines' => $s->lines->map(fn ($l) => ['refill_line_id' => $l->vendor_refill_line_id, 'item' => $l->refillLine->item->item_code.' · '.$l->refillLine->item->item_name, 'quantity' => $l->shipped_quantity])]), 'locations' => Location::active()->get(['id', 'location_code', 'location_name'])]);
    }

    public function store(Request $request, VendorRefillShipment $shipment, PostRefillReceipt $a)
    {
        abort_unless($request->user()->isWarehouse(), 403);
        $d = $request->validate(['vendor_do_number' => ['required', 'string', 'max:100'], 'received_at' => ['required', 'date'], 'lines' => ['required', 'array', 'min:1'], 'lines.*.refill_line_id' => ['required', 'uuid'], 'lines.*.location_id' => ['required', 'uuid', 'exists:locations,id'], 'lines.*.quantity' => ['required', 'numeric', 'min:0.0001', 'decimal:0,4']]);
        $a->handle($shipment, $request->user(), $d['vendor_do_number'], CarbonImmutable::parse($d['received_at'], 'Asia/Makassar'), $d['lines'], (string) Str::uuid());

        return back()->with('success','Incoming Refill berhasil diposting.');
    }
}
