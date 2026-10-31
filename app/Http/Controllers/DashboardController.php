<?php

namespace App\Http\Controllers;

use App\Models\MaterialRequest;
use App\Models\StockTransaction;
use App\Queries\DashboardAnalytics;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $user = $request->user();
        $vendorId = $user->isVendor() ? $user->vendor_id : null;
        $analytics = DashboardAnalytics::for($vendorId);

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
                'posted_at' => $transaction->posted_at?->timezone('Asia/Makassar')->format('d/m/Y H:i'),
                'lines' => $transaction->ledger_entries_count,
            ]);

        return Inertia::render('Dashboard', [
            'metrics' => [
                ...$analytics->metrics(),
                'active_requests' => $user->isVendor() ? null : MaterialRequest::active()->count(),
            ],
            'lists' => $analytics->lists(),
            'charts' => ['movement' => $analytics->movement(), 'composition' => $analytics->composition()],
            'quick_links' => $this->quickLinks($user->isVendor()),
            'recent_transactions' => $user->isVendor() ? [] : $recent,
            'generated_at' => now()->timezone('Asia/Makassar')->format('d/m/Y H:i'),
        ]);
    }

    /** @return list<array{label: string, icon: string, url: string}> */
    private function quickLinks(bool $vendor): array
    {
        if ($vendor) {
            return [
                ['label' => 'Vendor Refill', 'icon' => 'truck', 'url' => route('refills.index')],
                ['label' => 'Stock Sistem', 'icon' => 'warehouse', 'url' => route('monitoring.system-stock.index')],
                ['label' => 'PR, PO & Dokumen', 'icon' => 'layers', 'url' => route('procurement.index')],
            ];
        }

        return [
            ['label' => 'Data IN', 'icon' => 'arrowDown', 'url' => route('manual-data-in.index')],
            ['label' => 'Data OUT', 'icon' => 'box', 'url' => route('material-requests.index')],
            ['label' => 'PR, PO & Dokumen', 'icon' => 'layers', 'url' => route('procurement.index')],
            ['label' => 'Stock Sistem', 'icon' => 'warehouse', 'url' => route('monitoring.system-stock.index')],
            ['label' => 'Stock Opname', 'icon' => 'checkCircle', 'url' => route('stock-opname.index')],
        ];
    }
}
