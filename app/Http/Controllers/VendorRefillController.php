<?php

namespace App\Http\Controllers;

use App\Actions\Refills\CloseVendorRefill;
use App\Actions\Refills\CreateRefillShipment;
use App\Actions\Refills\CreateVendorRefill;
use App\Actions\Refills\ProposeRefillRevision;
use App\Actions\Refills\ReviewRefillRevision;
use App\Models\Vendor;
use App\Models\VendorRefill;
use App\Models\VendorRefillRevision;
use App\Services\Refills\RefillSuggestion;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Inertia\Inertia;

class VendorRefillController extends Controller
{
    public function index(Request $request)
    {
        $q = VendorRefill::with('vendor')->latest();
        if ($request->user()->isVendor()) {
            $q->where('vendor_id', $request->user()->vendor_id);
        }

        return Inertia::render('Refills/Index', ['refills' => $q->paginate(20)->through(fn ($r) => ['id' => $r->id, 'number' => $r->refill_no, 'vendor' => $r->vendor->vendor_name, 'status' => $r->status, 'requested_at' => $r->requested_at?->timezone('Asia/Makassar')->format('d/m/Y H:i'), 'show_url' => route('refills.show', $r)]), 'can_create' => $request->user()->isAdmin(), 'create_url' => route('refills.create')]);
    }

    public function create(Request $request, RefillSuggestion $s)
    {
        abort_unless($request->user()->isAdmin(), 403);
        $vs = Vendor::active()->with(['vendorItems' => fn ($q) => $q->active()->with('item')->whereHas('item', fn ($i) => $i->active()->where('ownership_type', 'CONSIGNMENT'))])->get();

        return Inertia::render('Refills/Create', ['store_url' => route('refills.store'), 'index_url' => route('refills.index'), 'vendors' => $vs->map(fn ($v) => ['id' => $v->id, 'code' => $v->vendor_code, 'name' => $v->vendor_name, 'items' => $v->vendorItems->map(fn ($r) => ['id' => $r->item_id, 'code' => $r->item->item_code, 'name' => $r->item->item_name, 'target' => $r->item->minimum_stock, 'suggestion' => $s->for($r)])])]);
    }

    public function store(Request $request, CreateVendorRefill $a)
    {
        abort_unless($request->user()->isAdmin(), 403);
        $d = $request->validate(['vendor_id' => ['required', 'uuid', 'exists:vendors,id'], 'notes' => ['nullable', 'string', 'max:2000'], 'lines' => ['required', 'array', 'min:1'], 'lines.*.item_id' => ['required', 'uuid'], 'lines.*.quantity' => ['required', 'integer', 'min:1']]);
        $r = $a->handle(Vendor::findOrFail($d['vendor_id']), $request->user(), $d['lines'], $d['notes'] ?? null);

        return to_route('refills.show', $r)->with('success', 'Vendor Refill berhasil dibuat.');
    }

    public function show(Request $request, VendorRefill $refill)
    {
        abort_if($request->user()->isVendor() && $request->user()->vendor_id !== $refill->vendor_id, 403);
        $refill->load(['vendor', 'lines.item', 'lines.revisions', 'lines.shipmentLines', 'lines.receiptLines.receipt', 'shipments', 'receipts.lines']);

        return Inertia::render('Refills/Show', ['role' => $request->user()->role->value, 'refill' => ['number' => $refill->refill_no, 'vendor' => $refill->vendor->vendor_name, 'status' => $refill->status, 'revision_url' => route('refills.revisions.store', $refill), 'shipment_url' => route('refills.shipments.store', $refill), 'close_url' => route('refills.close', $refill), 'lines' => $refill->lines->map(fn ($line) => $this->linePayload($line)), 'shipments' => $refill->shipments, 'receipts' => $refill->receipts]]);
    }

    public function revision(Request $request, VendorRefill $refill, ProposeRefillRevision $a)
    {
        abort_unless($request->user()->isVendor() && $request->user()->vendor_id === $refill->vendor_id, 403);
        $d = $request->validate(['line_id' => ['required', 'uuid'], 'quantity' => ['required', 'integer', 'min:1'], 'reason' => ['required', 'string', 'max:2000']]);
        $a->handle($refill->lines()->findOrFail($d['line_id']), $request->user(), (string) $d['quantity'], $d['reason']);

        return back()->with('success', 'Usulan revisi dikirim.');
    }

    public function review(Request $request, VendorRefillRevision $revision, ReviewRefillRevision $a)
    {
        abort_unless($request->user()->isAdmin(), 403);
        $d = $request->validate(['approve' => ['required', 'boolean'], 'note' => ['nullable', 'string', 'max:2000']]);
        $a->handle($revision, $request->user(), $request->boolean('approve'), $d['note'] ?? null);

        return back()->with('success', 'Revisi ditinjau.');
    }

    public function shipment(Request $request, VendorRefill $refill, CreateRefillShipment $a)
    {
        abort_unless($request->user()->isVendor() && $request->user()->vendor_id === $refill->vendor_id, 403);
        $d = $request->validate(['document_no' => ['required', 'string', 'max:100'], 'expedition' => ['nullable', 'string', 'max:150'], 'shipped_at' => ['required', 'date'], 'lines' => ['required', 'array', 'min:1'], 'lines.*.refill_line_id' => ['required', 'uuid'], 'lines.*.quantity' => ['required', 'integer', 'min:1']]);
        $a->handle($refill, $request->user(), $d['document_no'], CarbonImmutable::parse($d['shipped_at'], 'Asia/Makassar'), $d['lines'], $d['expedition'] ?? null);

        return back()->with('success', 'Pengiriman tersimpan.');
    }

    public function close(Request $request, VendorRefill $refill, CloseVendorRefill $a)
    {
        abort_unless($request->user()->isAdmin(), 403);
        $d = $request->validate(['reason' => ['required', 'string', 'max:2000']]);
        $a->handle($refill, $request->user(), $d['reason']);

        return back()->with('success', 'Sisa refill ditutup.');
    }

    private function linePayload($line): array
    {
        $shipped = $line->shipmentLines->sum('shipped_quantity');
        $received = $line->receiptLines->filter(fn ($row) => $row->receipt->status === 'POSTED')->sum('received_quantity');

        return ['id' => $line->id, 'item' => $line->item->item_code.' · '.$line->item->item_name, 'quantity' => (int) $line->requested_quantity, 'shipped' => (int) $shipped, 'received' => (int) $received, 'outstanding' => max(0, (int) $line->requested_quantity - (int) $received), 'revisions' => $line->revisions->map(fn ($revision) => ['id' => $revision->id, 'quantity' => (int) $revision->proposed_quantity, 'reason' => $revision->reason, 'status' => $revision->status, 'review_url' => route('refills.revisions.review', $revision)])];
    }
}
