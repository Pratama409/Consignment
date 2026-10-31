<?php

namespace App\Http\Controllers\Inventory;

use App\Actions\Inventory\ApproveStockOpname;
use App\Actions\Inventory\CancelStockOpname;
use App\Actions\Inventory\CloseStockOpname;
use App\Actions\Inventory\CreateStockOpnameSession;
use App\Actions\Inventory\OpnameMovementLog;
use App\Actions\Inventory\RecordOpnameCount;
use App\Actions\Inventory\ReopenOpnameLine;
use App\Actions\Inventory\SubmitOpnameCount;
use App\Enums\StockAdjustmentStatus;
use App\Enums\StockOpnameStatus;
use App\Http\Controllers\Controller;
use App\Models\StockBucket;
use App\Models\StockOpnameLine;
use App\Models\StockOpnameSession;
use Illuminate\Http\Request;
use Inertia\Inertia;

class StockOpnameController extends Controller
{
    public function index(Request $request)
    {
        abort_if($request->user()->isVendor(), 403);

        return Inertia::render('Inventory/Opname/Index', [
            'sessions' => StockOpnameSession::withCount('lines')->with('creator:id,name')->latest()->paginate(15)->through(fn (StockOpnameSession $session) => [
                'id' => $session->id, 'number' => $session->session_no, 'scope' => $session->scope_type->value, 'cycle_month' => $session->cycle_month,
                'status' => $session->status->value, 'lines' => $session->lines_count, 'creator' => $session->creator->name,
                'created_at' => $session->created_at?->timezone('Asia/Makassar')->format('d/m/Y H:i'), 'show_url' => route('stock-opname.show', $session),
            ]),
            'can' => ['create' => $request->user()->isAdmin()],
            'create_url' => route('stock-opname.create'),
        ]);
    }

    public function create(Request $request)
    {
        abort_unless($request->user()->isAdmin(), 403);

        return Inertia::render('Inventory/Opname/Create', [
            'store_url' => route('stock-opname.store'), 'index_url' => route('stock-opname.index'),
            'buckets' => StockBucket::with([...StockBucket::IDENTITY, 'balance'])->get()->map(fn (StockBucket $bucket) => [
                'id' => $bucket->id, 'item' => $this->itemLabel($bucket), 'vendor' => $bucket->vendorItem?->vendor?->vendor_name,
                'location' => $bucket->location->location_code, 'quantity' => $bucket->balance?->quantity ?? '0.0000',
            ])->sortBy('item')->values(),
        ]);
    }

    public function store(Request $request, CreateStockOpnameSession $create)
    {
        abort_unless($request->user()->isAdmin(), 403);
        $input = $request->validate([
            'scope_type' => ['required', 'string', 'max:20'], 'cycle_month' => ['nullable', 'string', 'max:7'],
            'bucket_ids' => ['nullable', 'array'], 'bucket_ids.*' => ['string'], 'note' => ['nullable', 'string', 'max:2000'],
        ]);
        $session = $create->handle($input, $request->user());

        return to_route('stock-opname.show', $session)->with('success', 'Sesi Stock Opname dibuat.');
    }

    public function show(Request $request, StockOpnameSession $session, OpnameMovementLog $log)
    {
        $user = $request->user();
        abort_if($user->isVendor(), 403);
        $isAdmin = $user->isAdmin();
        $session->load(['creator', 'counter', 'approver', 'lines.bucket' => fn ($query) => $query->with(StockBucket::IDENTITY), 'lines.adjustment', 'lines.session']);
        $status = $session->status;

        return Inertia::render('Inventory/Opname/Show', [
            'session' => [
                'id' => $session->id, 'number' => $session->session_no, 'scope' => $session->scope_type->value, 'cycle_month' => $session->cycle_month,
                'status' => $status->value, 'note' => $session->note, 'creator' => $session->creator->name, 'counter' => $session->counter?->name, 'approver' => $session->approver?->name,
                'submitted_at' => $session->submitted_at?->timezone('Asia/Makassar')->format('d/m/Y H:i'), 'completed_at' => $session->completed_at?->timezone('Asia/Makassar')->format('d/m/Y H:i'),
            ],
            // A Warehouse user never receives the system quantity, the difference, or anything derived from them.
            'lines' => $session->lines->map(fn (StockOpnameLine $line) => [
                'id' => $line->id, 'item' => $this->itemLabel($line->bucket), 'vendor' => $line->bucket->vendorItem?->vendor?->vendor_name,
                'location' => $line->bucket->location->location_code, 'unit' => $line->bucket->resolvedItem()?->unit?->name,
                'counted_quantity' => $line->counted_quantity, 'count_url' => route('stock-opname.count', [$session, $line]),
                ...($isAdmin ? [
                    'system_quantity' => $line->system_quantity, 'difference' => $line->difference(), 'state' => $line->state(),
                    'adjustment_status' => $line->adjustment?->status->value, 'movements' => $log->forLine($line),
                    'recount_url' => $status === StockOpnameStatus::Resolving && $line->adjustment?->status === StockAdjustmentStatus::Rejected ? route('stock-opname.recount', [$session, $line]) : null,
                ] : []),
            ])->values(),
            'can' => [
                'count' => $user->isWarehouse() && $status === StockOpnameStatus::Counting,
                'submit' => $user->isWarehouse() && $status === StockOpnameStatus::Counting,
                'approve' => $isAdmin && $status === StockOpnameStatus::Counted,
                'close' => $isAdmin && $status === StockOpnameStatus::Resolving,
                'cancel' => $isAdmin && in_array($status, [StockOpnameStatus::Counting, StockOpnameStatus::Counted], true),
            ],
            'urls' => [
                'index' => route('stock-opname.index'), 'submit' => route('stock-opname.submit', $session), 'approve' => route('stock-opname.approve', $session),
                'close' => route('stock-opname.close', $session), 'cancel' => route('stock-opname.cancel', $session), 'report' => route('stock-opname.report', $session),
            ],
        ]);
    }

    public function report(Request $request, StockOpnameSession $session, OpnameMovementLog $log)
    {
        abort_unless($request->user()->isAdmin(), 403);
        $session->load(['creator', 'counter', 'approver', 'lines.bucket' => fn ($query) => $query->with(StockBucket::IDENTITY), 'lines.session']);
        $person = fn ($user) => $user === null ? null : ['name' => $user->name, 'position' => $user->position];

        return Inertia::render('Inventory/Opname/Report', [
            'session' => [
                'number' => $session->session_no, 'scope' => $session->scope_type->value, 'cycle_month' => $session->cycle_month, 'status' => $session->status->value, 'note' => $session->note,
                'creator' => $person($session->creator), 'counter' => $person($session->counter), 'approver' => $person($session->approver),
                'submitted_at' => $session->submitted_at?->timezone('Asia/Makassar')->format('d/m/Y H:i'), 'approved_at' => $session->approved_at?->timezone('Asia/Makassar')->format('d/m/Y H:i'),
                'completed_at' => $session->completed_at?->timezone('Asia/Makassar')->format('d/m/Y H:i'),
            ],
            'lines' => $session->lines->map(fn (StockOpnameLine $line) => [
                'item' => $this->itemLabel($line->bucket), 'vendor' => $line->bucket->vendorItem?->vendor?->vendor_name, 'location' => $line->bucket->location->location_code,
                'system_quantity' => $line->system_quantity, 'counted_quantity' => $line->counted_quantity, 'difference' => $line->difference(), 'state' => $line->state(),
                'movements' => $log->forLine($line),
            ])->values(),
            'back_url' => route('stock-opname.show', $session),
            'printed_at' => now()->timezone('Asia/Makassar')->format('d/m/Y H:i'),
        ]);
    }

    public function count(Request $request, StockOpnameSession $session, StockOpnameLine $line, RecordOpnameCount $record)
    {
        abort_unless($request->user()->isWarehouse(), 403);
        abort_unless($line->stock_opname_session_id === $session->id, 404);
        $input = $request->validate(['quantity' => ['required', 'string', 'max:30']]);
        $record->handle($line, $input['quantity'], $request->user());

        return back()->with('success', 'Hasil hitung disimpan.');
    }

    public function submit(Request $request, StockOpnameSession $session, SubmitOpnameCount $submit)
    {
        abort_unless($request->user()->isWarehouse(), 403);
        $submit->handle($session, $request->user());

        return back()->with('success', 'Hasil hitung dikirim ke Admin.');
    }

    public function approve(Request $request, StockOpnameSession $session, ApproveStockOpname $approve)
    {
        abort_unless($request->user()->isAdmin(), 403);
        $approve->handle($session, $request->user());

        return back()->with('success', 'Stock Opname disetujui.');
    }

    public function close(Request $request, StockOpnameSession $session, CloseStockOpname $close)
    {
        abort_unless($request->user()->isAdmin(), 403);
        $close->handle($session, $request->user());

        return back()->with('success', 'Sesi Stock Opname ditutup.');
    }

    public function cancel(Request $request, StockOpnameSession $session, CancelStockOpname $cancel)
    {
        abort_unless($request->user()->isAdmin(), 403);
        $input = $request->validate(['reason' => ['required', 'string', 'max:2000']]);
        $cancel->handle($session, $request->user(), $input['reason']);

        return back()->with('success', 'Sesi Stock Opname dibatalkan.');
    }

    public function recount(Request $request, StockOpnameSession $session, StockOpnameLine $line, ReopenOpnameLine $reopen)
    {
        abort_unless($request->user()->isAdmin(), 403);
        abort_unless($line->stock_opname_session_id === $session->id, 404);
        $reopen->handle($line, $request->user());

        return back()->with('success', 'Baris dikembalikan untuk dihitung ulang.');
    }

    private function itemLabel(StockBucket $bucket): string
    {
        $item = $bucket->resolvedItem();

        return $item->item_code.' · '.$item->item_name;
    }
}
