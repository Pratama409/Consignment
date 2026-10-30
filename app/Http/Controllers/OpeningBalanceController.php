<?php

namespace App\Http\Controllers;

use App\Actions\OpeningBalance\ActivateOpeningBalance;
use App\Actions\OpeningBalance\SaveOpeningBalanceBatch;
use App\Http\Requests\OpeningBalance\StoreOpeningBalanceRequest;
use App\Models\InventoryPeriod;
use App\Models\OpeningBalanceBatch;
use App\Services\OpeningBalance\WorkbookReader;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class OpeningBalanceController extends Controller
{
    public function index(Request $request)
    {
        $this->authorizeView($request);

        return Inertia::render('OpeningBalance/Index', [
            'can_manage' => $request->user()->isAdmin(),
            'upload_url' => route('opening-balances.store'),
            'batches' => OpeningBalanceBatch::withCount('lines')->with('period')->latest()->take(10)->get()->map(fn ($batch) => [
                'id' => $batch->id,
                'source' => $batch->source,
                'status' => $batch->status->value,
                'line_count' => $batch->lines_count,
                'cutoff_at' => $batch->period->cutoff_at->timezone('Asia/Makassar')->format('d/m/Y H:i'),
                'show_url' => route('opening-balances.show', $batch),
            ]),
        ]);
    }

    public function store(StoreOpeningBalanceRequest $request, WorkbookReader $reader, SaveOpeningBalanceBatch $save)
    {
        $input = $request->validated();
        $rows = $request->hasFile('file') ? $reader->read($request->file('file')) : $input['rows'];
        $batch = DB::transaction(function () use ($input, $rows, $request, $save) {
            $cutoff = CarbonImmutable::createFromFormat('Y-m-d\TH:i', $input['cutoff_at'], 'Asia/Makassar')->utc();
            $period = InventoryPeriod::firstOrCreate(['cutoff_at' => $cutoff]);

            return $save->handle($rows, $period, $request->user(), $request->hasFile('file') ? $request->file('file')->getClientOriginalName() : 'Entri manual');
        });

        return to_route('opening-balances.show', $batch)->with('success', 'Preview saldo awal berhasil dibuat. Belum ada stok yang diposting.');
    }

    public function show(Request $request, OpeningBalanceBatch $openingBalance)
    {
        $this->authorizeView($request);
        $openingBalance->load(['period', 'lines.item', 'lines.location', 'lines.vendorItem.vendor']);

        return Inertia::render('OpeningBalance/Preview', [
            'batch' => [
                'id' => $openingBalance->id,
                'source' => $openingBalance->source,
                'status' => $openingBalance->status->value,
                'cutoff_at' => $openingBalance->period->cutoff_at->timezone('Asia/Makassar')->format('d/m/Y H:i'),
                'lines' => $openingBalance->lines->map(fn ($line) => [
                    'row_number' => $line->row_number,
                    'item_code' => $line->item->item_code,
                    'item_name' => $line->item->item_name,
                    'vendor' => $line->vendorItem?->vendor?->vendor_name,
                    'location' => $line->location->location_code,
                    'quantity' => $line->quantity,
                ]),
            ],
            'can_activate' => $request->user()->isAdmin() && $openingBalance->status->value === 'PREVIEW',
            'activate_url' => route('opening-balances.activate', $openingBalance),
            'index_url' => route('opening-balances.index'),
        ]);
    }

    public function activate(Request $request, OpeningBalanceBatch $openingBalance, ActivateOpeningBalance $activate)
    {
        abort_unless($request->user()->isAdmin(), 403);
        $input = $request->validate(['idempotency_key' => ['required', 'string', 'max:100']]);
        try {
            $activate->handle($openingBalance, $request->user(), $input['idempotency_key']);
        } catch (ValidationException $exception) {
            throw ValidationException::withMessages(['activation' => collect($exception->errors())->flatten()->implode(' ')]);
        }

        return to_route('opening-balances.show', $openingBalance)->with('success', 'Saldo awal berhasil diaktifkan. Periode persediaan sekarang ACTIVE.');
    }

    private function authorizeView(Request $request): void
    {
        abort_if($request->user()->isVendor(), 403);
    }
}
