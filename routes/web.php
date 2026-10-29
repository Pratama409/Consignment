<?php

use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\MasterData\ImportController;
use App\Http\Controllers\MasterData\ItemController;
use App\Http\Controllers\MasterData\ItemExportController;
use App\Http\Controllers\MasterData\ReferenceController;
use App\Http\Controllers\MasterData\UnitController;
use App\Http\Controllers\OpeningBalanceController;
use App\Http\Controllers\Settings\ShiftDefinitionController;
use App\Http\Controllers\UserManagement\UserController;
use App\Http\Middleware\EnsureActiveUser;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthenticatedSessionController::class, 'create'])->name('login');
    Route::post('/login', [AuthenticatedSessionController::class, 'store']);
});

Route::middleware(['auth', EnsureActiveUser::class])->group(function () {
    Route::get('/settings/shifts', [ShiftDefinitionController::class, 'index'])->name('shifts.index');
    Route::post('/settings/shifts', [ShiftDefinitionController::class, 'store'])->name('shifts.store');
    Route::get('/inventory/opening-balance', [OpeningBalanceController::class, 'index'])->name('opening-balances.index');
    Route::post('/inventory/opening-balance', [OpeningBalanceController::class, 'store'])->name('opening-balances.store');
    Route::get('/inventory/opening-balance/{openingBalance}', [OpeningBalanceController::class, 'show'])->whereUuid('openingBalance')->name('opening-balances.show');
    Route::post('/inventory/opening-balance/{openingBalance}/activate', [OpeningBalanceController::class, 'activate'])->whereUuid('openingBalance')->name('opening-balances.activate');
    Route::get('/users', [UserController::class, 'index'])->name('users.index');
    Route::get('/users/create', [UserController::class, 'create'])->name('users.create');
    Route::post('/users', [UserController::class, 'store'])->name('users.store');
    Route::get('/users/{user}/edit', [UserController::class, 'edit'])->whereUuid('user')->name('users.edit');
    Route::put('/users/{user}', [UserController::class, 'update'])->whereUuid('user')->name('users.update');
    Route::patch('/users/{user}/status', [UserController::class, 'status'])->whereUuid('user')->name('users.status');
    Route::get('/', fn () => to_route('items.index'))->name('home');
    Route::post('/logout', [AuthenticatedSessionController::class, 'destroy'])->name('logout');
    Route::get('/master-data/satuan', [UnitController::class, 'index'])->name('units.index');
    Route::post('/master-data/satuan', [UnitController::class, 'store'])->name('units.store');
    Route::put('/master-data/satuan/{unit}', [UnitController::class, 'update'])->name('units.update');
    Route::patch('/master-data/satuan/{unit}/status', [UnitController::class, 'status'])->name('units.status');
    foreach (['vendors' => 'vendor', 'locations' => 'lokasi', 'categories' => 'kategori'] as $resource => $path) {
        Route::get('/master-data/'.$path, [ReferenceController::class, 'index'])->defaults('resource', $resource)->name($resource.'.index');
        Route::post('/master-data/'.$path, [ReferenceController::class, 'store'])->defaults('resource', $resource)->name($resource.'.store');
        Route::put('/master-data/'.$path.'/{record}', [ReferenceController::class, 'update'])->defaults('resource', $resource)->whereUuid('record')->name($resource.'.update');
        Route::patch('/master-data/'.$path.'/{record}/status', [ReferenceController::class, 'status'])->defaults('resource', $resource)->whereUuid('record')->name($resource.'.status');
    }
    Route::get('/master-data/barang', [ItemController::class, 'index'])->name('items.index');
    Route::get('/master-data/barang/export', [ItemExportController::class, 'index'])->name('items.export');
    Route::get('/master-data/barang/tambah', [ItemController::class, 'create'])->name('items.create');
    Route::post('/master-data/barang', [ItemController::class, 'store'])->name('items.store');
    Route::get('/master-data/barang/{item}', [ItemController::class, 'show'])->whereUuid('item')->name('items.show');
    Route::get('/master-data/barang/{item}/edit', [ItemController::class, 'edit'])->whereUuid('item')->name('items.edit');
    Route::put('/master-data/barang/{item}', [ItemController::class, 'update'])->whereUuid('item')->name('items.update');
    Route::patch('/master-data/barang/{item}/status', [ItemController::class, 'status'])->whereUuid('item')->name('items.status');
    Route::get('/master-data/import', [ImportController::class, 'index'])->name('imports.index');
    Route::get('/master-data/import/template', [ItemExportController::class, 'template'])->name('imports.template');
    Route::post('/master-data/import', [ImportController::class, 'store'])->name('imports.store');
    Route::get('/master-data/import/{import}', [ImportController::class, 'show'])->whereUuid('import')->name('imports.show');
    Route::put('/master-data/import/{import}/options', [ImportController::class, 'options'])->whereUuid('import')->name('imports.options');
    Route::put('/master-data/import/{import}/groups/{group}', [ImportController::class, 'decision'])->whereUuid('import')->name('imports.decision');
    Route::put('/master-data/import/{import}/rows/{row}', [ImportController::class, 'correction'])->whereUuid('import')->whereNumber('row')->name('imports.correction');
    Route::post('/master-data/import/{import}/confirm', [ImportController::class, 'confirm'])->whereUuid('import')->name('imports.confirm');
    Route::get('/master-data/import/{import}/export', [ImportController::class, 'export'])->whereUuid('import')->name('imports.export');
});
