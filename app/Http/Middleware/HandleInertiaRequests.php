<?php

namespace App\Http\Middleware;

use App\Models\Item;
use App\Models\VendorRefillFollowUp;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    public function share(Request $request): array
    {
        $user = $request->user();
        $routeName = $request->route()?->getName() ?? '';
        $section = match (true) {
            str_starts_with($routeName, 'monitoring.transactions') => 'monitoring-transactions',
            str_starts_with($routeName, 'monitoring.shifts') => 'monitoring-shifts',
            str_starts_with($routeName, 'monitoring.system-stock') => 'monitoring-stock',
            str_starts_with($routeName, 'monitoring.reconciliations') => 'monitoring-reconciliations',
            default => explode('.', $routeName)[0],
        };
        $labels = ['home' => 'Dashboard', 'manual-data-in' => 'Data IN', 'procurement' => 'Pengadaan', 'monitoring-transactions' => 'Rekap Transaksi', 'monitoring-shifts' => 'Rekap Shift', 'monitoring-stock' => 'Stock Sistem', 'monitoring-reconciliations' => 'BA Rekonsiliasi', 'material-requests' => 'Data OUT', 'refills' => 'Vendor Refill', 'incoming-refills' => 'Incoming Refill', 'stock-corrections' => 'Koreksi Stok', 'stock-opname' => 'Stock Opname', 'opening-balances' => 'Saldo Awal', 'shifts' => 'Pengaturan Shift', 'users' => 'Manajemen Pengguna', 'items' => 'Barang', 'vendors' => 'Vendor', 'units' => 'Satuan', 'locations' => 'Lokasi', 'categories' => 'Kategori', 'departments' => 'Departemen', 'imports' => 'Import Master PN'];

        return [
            ...parent::share($request),
            'auth' => [
                'user' => $user ? [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'role' => $user->role->value,
                    'role_label' => $user->role->label(),
                    'vendor_id' => $user->isVendor() ? $user->vendor_id : null,
                    'vendor_name' => $user->isVendor() ? $user->vendor?->vendor_name : null,
                ] : null,
                'abilities' => [
                    'manage_users' => $user?->isAdmin() ?? false,
                    'manage_master_data' => $user?->isAdmin() ?? false,
                    'view_reference_masters' => $user ? ! $user->isVendor() : false,
                ],
            ],
            'flash' => fn (): array => [
                'success' => $request->session()->get('success'),
            ],
            'routes' => [
                'home' => route('home'),
                'manualDataInIndex' => route('manual-data-in.index'),
                'procurementIndex' => route('procurement.index'),
                'monitoringTransactionsIndex' => route('monitoring.transactions.index'),
                'monitoringShiftsIndex' => route('monitoring.shifts.index'),
                'monitoringSystemStockIndex' => route('monitoring.system-stock.index'),
                'monitoringReconciliationsIndex' => route('monitoring.reconciliations.index'),
                'itemsIndex' => route('items.index'),
                'vendorsIndex' => route('vendors.index'),
                'vendorsImport' => route('vendors.import.create'),
                'locationsIndex' => route('locations.index'),
                'categoriesIndex' => route('categories.index'),
                'departmentsIndex' => route('departments.index'),
                'importsIndex' => route('imports.index'),
                'unitsIndex' => route('units.index'),
                'usersIndex' => route('users.index'),
                'shiftsIndex' => route('shifts.index'),
                'openingBalancesIndex' => route('opening-balances.index'),
                'stockCorrectionsIndex' => route('stock-corrections.index'),
                'stockOpnameIndex' => route('stock-opname.index'),
                'refillsIndex' => route('refills.index'),
                'incomingRefillsIndex' => route('incoming-refills.index'),
                'materialRequestsIndex' => route('material-requests.index'),
                'login' => route('login'),
                'logout' => route('logout'),
            ],
            'navigation' => ['badges' => ['refills' => fn () => $user?->isVendor() ? VendorRefillFollowUp::whereNull('acknowledged_at')->whereHas('refill', fn ($refill) => $refill->where('vendor_id', $user->vendor_id))->count() : 0], 'current' => $section, 'label' => $labels[$section] ?? 'Master Data', 'can_import' => $user?->can('create', Item::class) ?? false],
        ];
    }
}
