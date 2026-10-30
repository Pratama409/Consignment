<?php

namespace App\Http\Middleware;

use App\Models\Item;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    public function share(Request $request): array
    {
        $user = $request->user();
        $section = explode('.', $request->route()?->getName() ?? '')[0];
        $labels = ['material-requests' => 'Material Request', 'refills' => 'Vendor Refill', 'incoming-refills' => 'Incoming Refill', 'stock-corrections' => 'Koreksi Stok', 'opening-balances' => 'Saldo Awal', 'shifts' => 'Pengaturan Shift', 'users' => 'Manajemen Pengguna', 'items' => 'Barang', 'vendors' => 'Vendor', 'units' => 'Satuan', 'locations' => 'Lokasi', 'categories' => 'Kategori', 'imports' => 'Import Master PN'];

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
                'itemsIndex' => route('items.index'),
                'vendorsIndex' => route('vendors.index'),
                'locationsIndex' => route('locations.index'),
                'categoriesIndex' => route('categories.index'),
                'importsIndex' => route('imports.index'),
                'unitsIndex' => route('units.index'),
                'usersIndex' => route('users.index'),
                'shiftsIndex' => route('shifts.index'),
                'openingBalancesIndex' => route('opening-balances.index'),
                'stockCorrectionsIndex' => route('stock-corrections.index'),
                'refillsIndex' => route('refills.index'),
                'incomingRefillsIndex' => route('incoming-refills.index'),
                'materialRequestsIndex' => route('material-requests.index'),
                'login' => route('login'),
                'logout' => route('logout'),
            ],
            'navigation' => ['current' => $section, 'label' => $labels[$section] ?? 'Master Data', 'can_import' => $user?->can('create', Item::class) ?? false],
        ];
    }
}
