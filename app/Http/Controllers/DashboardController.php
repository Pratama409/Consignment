<?php

namespace App\Http\Controllers;

use App\Models\MaterialRequest;
use App\Models\ProcurementRequirement;
use App\Models\StockBalance;
use App\Models\StockTransaction;
use App\Models\VendorRefill;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $user = $request->user();
        $vendorId = $user->isVendor() ? $user->vendor_id : null;

        $refills = VendorRefill::query()->whereNotIn('status', ['COMPLETED', 'CLOSED_PARTIAL', 'CANCELLED']);
        $procurement = ProcurementRequirement::query()->whereNotIn('status', ['COMPLETE', 'VOID']);
        $balances = StockBalance::query();
        if ($vendorId) {
            $refills->where('vendor_id', $vendorId);
            $procurement->whereHas('vendorItem', fn ($query) => $query->where('vendor_id', $vendorId));
            $balances->whereHas('vendorItem', fn ($query) => $query->where('vendor_id', $vendorId));
        }

        $recent = StockTransaction::query()
            ->withCount('ledgerEntries')
            ->where('status', 'POSTED')
            ->latest('posted_at')
            ->limit(8)
            ->get()
            ->map(fn (StockTransaction $transaction) => [
                'id' => $transaction->id,
                'number' => $transaction->transaction_no,
                'type' => $transaction->type->value,
                'posted_at' => $transaction->posted_at?->format('d/m/Y H:i'),
                'lines' => $transaction->ledger_entries_count,
            ]);

        return Inertia::render('Dashboard', [
            'metrics' => [
                'stock_buckets' => $balances->count(),
                'open_refills' => $refills->count(),
                'active_requests' => $user->isVendor() ? null : MaterialRequest::active()->count(),
                'procurement_outstanding' => $procurement->count(),
            ],
            'recent_transactions' => $user->isVendor() ? [] : $recent,
        ]);
    }
}
