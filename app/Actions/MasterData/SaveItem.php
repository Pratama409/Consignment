<?php

namespace App\Actions\MasterData;

use App\Enums\OwnershipType;
use App\Models\Item;
use App\Models\Unit;
use App\Support\ItemCodeGenerator;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class SaveItem
{
    /**
     * Save a complete item form. Omitted relationships are deactivated, never recreated.
     *
     * @param  array<string, mixed>  $data
     */
    public function handle(array $data, ?Item $item = null): Item
    {
        $validated = Validator::make($data, [
            'item_code' => ['prohibited'],
            'part_number' => ['required', 'string', 'max:100'],
            'item_name' => ['required', 'string', 'max:200'],
            'ownership_type' => ['required', Rule::enum(OwnershipType::class)],
            'tracking_type' => ['sometimes', Rule::in(['QUANTITY'])],
            'unit_id' => ['required', 'uuid', Rule::exists('units', 'id')],
            'minimum_stock' => ['required', 'numeric', 'min:0', 'decimal:0,4'],
            'is_active' => ['sometimes', 'boolean'],
            'vendor_items' => ['sometimes', 'array', 'prohibited_if:ownership_type,STOCK'],
            'vendor_items.*' => ['array:vendor_id,location_id,is_active'],
            'vendor_items.*.vendor_id' => ['required', 'uuid', 'distinct', Rule::exists('vendors', 'id')],
            'vendor_items.*.location_id' => ['required', 'uuid', Rule::exists('locations', 'id')],
            'vendor_items.*.is_active' => ['sometimes', 'boolean'],
            'stock_locations' => ['sometimes', 'array', 'prohibited_if:ownership_type,CONSIGNMENT'],
            'stock_locations.*' => ['array:location_id,is_active'],
            'stock_locations.*.location_id' => ['required', 'uuid', 'distinct', Rule::exists('locations', 'id')],
            'stock_locations.*.is_active' => ['sometimes', 'boolean'],
        ], [
            'item_code.prohibited' => 'Kode internal dibuat sistem dan tidak dapat diisi manual.',
            'part_number.required' => 'Part number wajib diisi.',
            'part_number.max' => 'Part number maksimal 100 karakter.',
            'item_name.required' => 'Nama atau deskripsi barang wajib diisi.',
            'item_name.max' => 'Nama atau deskripsi maksimal 200 karakter.',
            'ownership_type.required' => 'Pilih jenis kepemilikan.',
            'ownership_type.enum' => 'Jenis kepemilikan tidak valid.',
            'tracking_type.in' => 'Cara pelacakan saat ini hanya kuantitas (QUANTITY).',
            'unit_id.required' => 'Pilih satuan barang.',
            'unit_id.uuid' => 'Pilihan satuan tidak valid.',
            'unit_id.exists' => 'Satuan tidak ditemukan. Muat ulang pilihan satuan.',
            'minimum_stock.required' => 'Target refill wajib diisi.',
            'minimum_stock.numeric' => 'Target refill harus berupa angka.',
            'minimum_stock.min' => 'Target refill tidak boleh negatif.',
            'minimum_stock.decimal' => 'Target refill maksimal empat angka desimal.',
            'is_active.boolean' => 'Status barang tidak valid.',
            'vendor_items.prohibited_if' => 'Barang STOCK tidak mempunyai hubungan Vendor.',
            'vendor_items.*.vendor_id.required' => 'Pilih Vendor pada baris ini.',
            'vendor_items.*.vendor_id.distinct' => 'Vendor yang sama tidak boleh dipilih berulang.',
            'vendor_items.*.vendor_id.exists' => 'Vendor tidak ditemukan. Muat ulang pilihan Vendor.',
            'vendor_items.*.vendor_id.uuid' => 'Pilihan Vendor tidak valid.',
            'vendor_items.*.location_id.required' => 'Pilih lokasi utama atau UNASSIGNED.',
            'vendor_items.*.location_id.exists' => 'Lokasi utama tidak ditemukan.',
            'vendor_items.*.location_id.uuid' => 'Pilihan lokasi utama tidak valid.',
            'vendor_items.*.is_active.boolean' => 'Status hubungan Vendor tidak valid.',
            'stock_locations.prohibited_if' => 'Penempatan barang consignment diatur melalui hubungan Vendor.',
            'stock_locations.*.location_id.required' => 'Pilih lokasi atau UNASSIGNED.',
            'stock_locations.*.location_id.distinct' => 'Lokasi yang sama tidak boleh dipilih berulang.',
            'stock_locations.*.location_id.exists' => 'Lokasi tidak ditemukan. Muat ulang pilihan lokasi.',
            'stock_locations.*.location_id.uuid' => 'Pilihan lokasi tidak valid.',
            'stock_locations.*.is_active.boolean' => 'Status penempatan tidak valid.',
        ])->validate();

        $vendors = $validated['vendor_items'] ?? [];
        $placements = $validated['stock_locations'] ?? [];

        if ($validated['ownership_type'] === OwnershipType::Consignment->value
            && ! collect($vendors)->contains(fn (array $vendor): bool => (bool) ($vendor['is_active'] ?? true))) {
            throw ValidationException::withMessages(['vendor_items' => 'Barang CONSIGNMENT wajib mempunyai minimal satu hubungan Vendor aktif.']);
        }

        return DB::transaction(function () use ($validated, $vendors, $placements, $item): Item {
            $record = $item?->exists ? Item::query()->lockForUpdate()->findOrFail($item->id) : new Item;
            $isNew = ! $record->exists;
            $attributes = Arr::only($validated, [
                'part_number', 'item_name', 'ownership_type', 'tracking_type',
                'unit_id', 'minimum_stock', 'is_active',
            ]);

            $attributes['part_number'] = trim($attributes['part_number']);
            $attributes['item_name'] = trim($attributes['item_name']);
            $attributes['is_active'] ??= $isNew ? true : $record->is_active;
            $attributes['tracking_type'] ??= 'QUANTITY';
            $record->fill($attributes);

            if ($isNew || $record->isDirty('unit_id') || ($record->isDirty('is_active') && $record->is_active)) {
                $unit = Unit::query()->sharedLock()->find($record->unit_id);

                if (! $unit?->is_active) {
                    throw ValidationException::withMessages(['unit_id' => 'Satuan harus terdaftar dan aktif.']);
                }
            }

            if ($isNew) {
                $record->item_code = app(ItemCodeGenerator::class)->next();
            }

            $record->save();

            if ($record->ownership_type === OwnershipType::Consignment) {
                // Activate replacements first, so the last active vendor is never removed mid-save.
                $existing = $record->vendorItems()->lockForUpdate()->get()->keyBy('vendor_id');

                foreach (collect($vendors)->sortByDesc(fn (array $vendor): bool => (bool) ($vendor['is_active'] ?? true)) as $vendor) {
                    $relation = $existing->get($vendor['vendor_id']) ?? $record->vendorItems()->make();
                    $relation->fill([
                        'vendor_id' => $vendor['vendor_id'],
                        'location_id' => $vendor['location_id'],
                        'is_active' => $vendor['is_active'] ?? true,
                    ])->save();
                }

                foreach ($record->vendorItems()->active()->whereNotIn('vendor_id', array_column($vendors, 'vendor_id'))->get() as $relation) {
                    $relation->update(['is_active' => false]);
                }

                if (! $record->vendorItems()->active()->exists()) {
                    throw ValidationException::withMessages(['vendor_items' => 'Barang CONSIGNMENT wajib mempunyai minimal satu hubungan Vendor aktif.']);
                }
            } else {
                $existing = $record->stockItemLocations()->lockForUpdate()->get()->keyBy('location_id');

                foreach ($placements as $placement) {
                    $relation = $existing->get($placement['location_id']) ?? $record->stockItemLocations()->make();
                    $relation->fill([
                        'location_id' => $placement['location_id'],
                        'is_active' => $placement['is_active'] ?? true,
                    ])->save();
                }

                foreach ($record->stockItemLocations()->active()->whereNotIn('location_id', array_column($placements, 'location_id'))->get() as $relation) {
                    $relation->update(['is_active' => false]);
                }
            }

            return $record->refresh()->load(['unit', 'vendorItems.vendor', 'vendorItems.location', 'stockItemLocations.location']);
        }, attempts: 3);
    }
}
