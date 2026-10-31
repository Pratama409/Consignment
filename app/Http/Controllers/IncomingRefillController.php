<?php

namespace App\Http\Controllers;

use App\Actions\Refills\PostRefillReceipt;
use App\Models\Location;
use App\Models\VendorRefillShipment;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Inertia\Inertia;

class IncomingRefillController extends Controller
{
    public function index(Request $request)
    {
        abort_if($request->user()->isVendor(), 403);
        $shipments = VendorRefillShipment::whereIn('status', ['DISPATCHED', 'PARTIALLY_RECEIVED'])
            ->whereHas('refill', fn ($query) => $query->whereNotIn('status', ['COMPLETED', 'CLOSED_PARTIAL', 'CANCELLED']))
            ->with(['refill.vendor', 'lines.refillLine.item', 'lines.refillLine.receiptLines.receipt'])->get();

        return Inertia::render('Refills/Incoming', [
            'can_receive' => $request->user()->isWarehouse(),
            'shipments' => $shipments->map(fn ($shipment) => [
                'id' => $shipment->id, 'refill' => $shipment->refill->refill_no, 'vendor' => $shipment->refill->vendor->vendor_name,
                'document_no' => $shipment->vendor_document_no, 'status' => $shipment->status,
                'receipt_url' => route('incoming-refills.receipts.store', $shipment),
                'lines' => $shipment->lines->map(function ($line) use ($shipment) {
                    $received = $line->refillLine->receiptLines->filter(fn ($row) => $row->receipt->status === 'POSTED' && $row->receipt->vendor_refill_shipment_id === $shipment->id)->sum('received_quantity');

                    return ['refill_line_id' => $line->vendor_refill_line_id, 'item' => $line->refillLine->item->item_code.' · '.$line->refillLine->item->item_name, 'quantity' => max(0, (int) $line->shipped_quantity - (int) $received)];
                })->filter(fn ($line) => (float) $line['quantity'] > 0)->values(),
            ]),
            'locations' => Location::active()->get(['id', 'location_code', 'location_name']),
        ]);
    }

    public function store(Request $request, VendorRefillShipment $shipment, PostRefillReceipt $action)
    {
        abort_unless($request->user()->isWarehouse(), 403);
        $data = $request->validate(['vendor_do_number' => ['required', 'string', 'max:100'], 'received_at' => ['required', 'date'], 'idempotency_key' => ['required', 'string', 'max:100'], 'lines' => ['required', 'array', 'min:1'], 'lines.*.refill_line_id' => ['required', 'uuid'], 'lines.*.location_id' => ['required', 'uuid', 'exists:locations,id'], 'lines.*.quantity' => ['required', 'integer', 'min:1']]);
        $action->handle($shipment, $request->user(), $data['vendor_do_number'], CarbonImmutable::parse($data['received_at'], 'Asia/Makassar'), $data['lines'], $data['idempotency_key']);

        return back()->with('success', 'Incoming Refill berhasil diposting.');
    }
}
