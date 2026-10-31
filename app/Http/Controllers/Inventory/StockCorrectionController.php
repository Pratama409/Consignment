<?php

namespace App\Http\Controllers\Inventory;

use App\Actions\Inventory\CreateStockAdjustment;
use App\Actions\Inventory\PostStockAdjustment;
use App\Actions\Inventory\ReverseStockTransaction;
use App\Actions\Inventory\ReviewStockAdjustment;
use App\Enums\StockAdjustmentStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Inventory\StoreStockCorrectionRequest;
use App\Models\Item;
use App\Models\Location;
use App\Models\StockAdjustment;
use App\Models\StockTransaction;
use App\Models\VendorItem;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;

class StockCorrectionController extends Controller
{
    public function index(Request $request)
    {
        $this->viewable($request);
        $input = $request->validate(['q' => ['nullable', 'string', 'max:100']]);
        $search = trim($input['q'] ?? '');
        $adjustments = StockAdjustment::withResolvedItem()->with(['location', 'vendorItem.vendor', 'requester', 'reviewer'])->latest()->paginate(20)->withQueryString()->through(fn ($record) => [
            'id' => $record->id, 'status' => $record->status->value, 'item' => $record->item->item_code.' · '.$record->item->item_name,
            'vendor' => $record->vendorItem?->vendor?->vendor_name, 'location' => $record->location->location_code,
            'direction' => $record->direction->value, 'quantity' => $record->quantity, 'reason' => $record->reason,
            'requester' => $record->requester->name, 'review_note' => $record->review_note,
            'review_url' => route('stock-corrections.review', $record), 'post_url' => route('stock-corrections.post', $record),
        ]);

        return Inertia::render('Inventory/Corrections/Index', [
            'adjustments' => $adjustments,
            'can_manage' => $request->user()->isAdmin(),
            'create_url' => route('stock-corrections.create'),
            'transaction_search' => $search,
            'recent_transactions' => StockTransaction::where('status', 'POSTED')->whereNull('reversal_of_id')
                ->when($search !== '', fn ($query) => $query->where('transaction_no', 'like', '%'.$search.'%'))
                ->with(['reversal', 'ledgerEntries' => fn ($entries) => $entries->withBucketIdentity()])->latest('posted_at')
                ->paginate(15, ['*'], 'transactions_page')->withQueryString()->through(fn ($transaction) => [
                    'id' => $transaction->id, 'number' => $transaction->transaction_no, 'type' => $transaction->type->value,
                    'posted_at' => $transaction->posted_at?->timezone('Asia/Makassar')->format('d/m/Y H:i'),
                    'reason' => $transaction->reason,
                    'impact' => $transaction->ledgerEntries->map(fn ($entry) => [
                        'item' => $entry->item->item_code.' · '.$entry->item->item_name,
                        'location' => $entry->location->location_code,
                        'quantity' => $entry->quantity,
                    ]),
                    'reversed' => $transaction->reversal !== null, 'reverse_url' => route('stock-corrections.reverse', $transaction),
                ]),
        ]);
    }

    public function create(Request $request)
    {
        abort_unless($request->user()->isAdmin() || $request->user()->isWarehouse(), 403);

        return Inertia::render('Inventory/Corrections/Create', [
            'store_url' => route('stock-corrections.store'), 'index_url' => route('stock-corrections.index'),
            'items' => Item::active()->orderBy('item_code')->get(['id', 'item_code', 'item_name', 'ownership_type']),
            'locations' => Location::active()->orderBy('location_code')->get(['id', 'location_code', 'location_name']),
            'vendor_items' => VendorItem::active()->with(['vendor:id,vendor_code,vendor_name', 'stockBuckets' => fn ($buckets) => $buckets->whereHas('balance', fn ($balance) => $balance->where('quantity', '>', 0))])->get(['id', 'item_id', 'vendor_id', 'location_id'])
                ->flatMap(fn ($pair) => $pair->usableLocationIds()->map(fn ($locationId) => ['id' => $pair->id, 'item_id' => $pair->item_id, 'vendor_id' => $pair->vendor_id, 'location_id' => $locationId, 'vendor' => $pair->vendor]))->values(),
        ]);
    }

    public function store(StoreStockCorrectionRequest $request, CreateStockAdjustment $create)
    {
        $create->handle($request->validated(), $request->user());

        return to_route('stock-corrections.index')->with('success', 'Draft penyesuaian stok dibuat.');
    }

    public function review(Request $request, StockAdjustment $stockAdjustment, ReviewStockAdjustment $review)
    {
        abort_unless($request->user()->isAdmin(), 403);
        $input = $request->validate(['approve' => ['required', 'boolean'], 'note' => ['required', 'string', 'max:2000']]);
        $review->handle($stockAdjustment, $request->boolean('approve'), $input['note'], $request->user());

        return back()->with('success', $request->boolean('approve') ? 'Penyesuaian disetujui.' : 'Penyesuaian ditolak.');
    }

    public function post(Request $request, StockAdjustment $stockAdjustment, PostStockAdjustment $post)
    {
        abort_unless($request->user()->isAdmin(), 403);
        abort_unless($stockAdjustment->status === StockAdjustmentStatus::Approved, 422);
        $post->handle($stockAdjustment, $request->user(), 'adjustment:'.$stockAdjustment->id);

        return back()->with('success', 'Penyesuaian berhasil diposting.');
    }

    public function reverse(Request $request, StockTransaction $stockTransaction, ReverseStockTransaction $reverse)
    {
        abort_unless($request->user()->isAdmin(), 403);
        $input = $request->validate(['reason' => ['required', 'string', 'max:2000']]);
        $reverse->handle($stockTransaction, $request->user(), $input['reason'], 'reversal:'.$stockTransaction->id.':'.Str::uuid());

        return back()->with('success', 'Transaksi berhasil dibalik melalui ledger reversal.');
    }

    private function viewable(Request $request): void
    {
        abort_if($request->user()->isVendor(), 403);
    }
}
