<?php

namespace App\Services\MasterData;

use App\Models\Category;
use App\Models\Item;
use App\Models\Location;
use App\Models\MasterDataImport;
use App\Models\Unit;
use App\Models\Vendor;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Validator;

class ImportPlanner
{
    private Collection $units;

    private Collection $categories;

    private Collection $vendors;

    private Collection $locations;

    private Collection $items;

    private Collection $candidates;

    public function build(MasterDataImport $import): array
    {
        $this->units = Unit::all()->keyBy(fn ($unit) => mb_strtoupper($unit->code));
        $this->categories = Category::all()->keyBy(fn ($category) => mb_strtoupper($category->code));
        $this->vendors = Vendor::all()->keyBy(fn ($vendor) => mb_strtoupper($vendor->vendor_code));
        $this->locations = Location::all()->keyBy(fn ($location) => mb_strtoupper($location->location_code));
        $this->items = Item::with(['category', 'vendorItems', 'stockItemLocations'])->get()->keyBy('item_code');
        $this->candidates = $this->items->groupBy(fn ($item) => $item->ownership_type->value.'|'.mb_strtoupper($item->part_number));
        $groups = [];
        $corrections = $import->corrections ?? [];
        $decisions = $import->decisions ?? [];
        foreach ($import->data['rows'] as $source) {
            $correction = $corrections[$source['number']] ?? [];
            $values = [...$source['values'], ...Arr::except($correction, ['skip'])];
            $code = mb_strtoupper(trim($values['ITEM_CODE'] ?? ''));
            $ownership = $this->ownership($values['MATERIAL_TYPE'] ?? '');
            $key = $code !== '' ? 'code:'.$code : json_encode([$ownership, mb_strtoupper(trim($values['PART_NUMBER'] ?? '')), mb_strtoupper(trim($values['DESCRIPTION'] ?? '')), mb_strtoupper(trim($values['UOM'] ?? ''))]);
            $id = sha1($key);
            $decision = $decisions[$id] ?? [];
            $targetCode = $code !== '' ? $code : (($decision['action'] ?? '') === 'match' ? mb_strtoupper(trim($decision['target_code'] ?? '')) : '');
            $target = $this->items->get($targetCode);
            $issues = array_diff_key($source['issues'] ?? [], $correction);
            if (isset($decision['minimum_stock']) && $decision['minimum_stock'] !== '') {
                $values['MINIMUM_STOCK'] = (string) $decision['minimum_stock'];
            }
            $row = $this->normalize($source['number'], $values, $issues, $target, $import->options);
            $row['skip'] = (bool) ($correction['skip'] ?? false);
            if (array_key_exists('minimum_stock', $decision) && $decision['minimum_stock'] !== null && $decision['minimum_stock'] !== '') {
                $row['minimum_stock'] = (string) $decision['minimum_stock'];
            }
            $groups[$id] ??= ['id' => $id, 'rows' => [], 'target_code' => $targetCode, 'decision' => $decision];
            $groups[$id]['rows'][] = $row;
        }

        $summary = ['source_rows' => count($import->data['rows']), 'groups' => count($groups), 'ready_groups' => 0, 'blocking_groups' => 0, 'skipped_rows' => 0, 'new_items' => 0, 'updated_items' => 0];
        $newVendors = [];
        $newLocations = [];
        $matched = [];
        foreach ($groups as &$group) {
            $unique = [];
            $seen = [];
            $duplicates = 0;
            foreach ($group['rows'] as &$row) {
                $row['duplicate_of'] = null;
                if ($row['skip']) {
                    continue;
                }
                $signature = json_encode(Arr::only($row, ['item_code', 'part_number', 'item_name', 'ownership_type', 'unit_code', 'category_code', 'minimum_stock', 'is_active', 'tracking_type', 'vendor_code', 'location_code', 'relation_active']));
                if (isset($seen[$signature])) {
                    $row['duplicate_of'] = $seen[$signature];
                    $duplicates++;
                } else {
                    $seen[$signature] = $row['number'];
                    $unique[] = $row;
                }
            }
            unset($row);
            $first = $unique[0] ?? $group['rows'][0];
            $candidateItems = $this->candidates->get($first['ownership_type'].'|'.mb_strtoupper($first['part_number']), collect());
            $group['action'] = $group['decision']['action'] ?? ($first['item_code'] !== '' ? 'match' : (count($unique) > 1 || $candidateItems->isNotEmpty() ? 'review' : 'create'));
            if (! $unique) {
                $group['action'] = 'skip';
            }
            $group['data'] = Arr::only($first, ['part_number', 'item_name', 'ownership_type', 'unit_id', 'unit_code', 'category_id', 'category_code', 'minimum_stock', 'is_active', 'tracking_type']);
            $group['unique_rows'] = $unique;
            $group['duplicate_count'] = $duplicates;
            $group['candidates'] = $candidateItems->take(12)->map(fn ($item) => $item->only('item_code', 'item_name', 'part_number'))->values()->all();
            $group['errors'] = [];
            foreach ($unique as $row) {
                foreach ($row['errors'] as $field => $message) {
                    $group['errors'][] = 'Baris '.$row['number'].' · '.$message;
                }
            }
            if ($group['action'] !== 'split' && $unique) {
                foreach (['part_number', 'item_name', 'ownership_type', 'unit_id', 'category_id', 'minimum_stock', 'is_active', 'tracking_type'] as $field) {
                    if (count(array_unique(array_map(fn ($row) => (string) $row[$field], $unique))) > 1) {
                        $group['errors'][] = 'Nilai '.$field.' berbeda dalam satu kelompok. Perbaiki baris sebelum digabung.';
                    }
                }
                if ($first['ownership_type'] === 'CONSIGNMENT') {
                    $byVendor = collect($unique)->groupBy('vendor_code');
                    foreach ($byVendor as $vendorRows) {
                        if ($vendorRows->pluck('location_code')->unique()->count() > 1) {
                            $group['errors'][] = 'Satu barang–Vendor hanya boleh mempunyai satu lokasi utama. Pilih lokasi atau lewati baris yang tidak dipakai.';
                        }
                    }
                }
                $relations = collect($unique)->groupBy(fn ($row) => $row['ownership_type'] === 'CONSIGNMENT' ? $row['vendor_code'] : $row['location_code']);
                foreach ($relations as $relationRows) {
                    if ($relationRows->pluck('relation_active')->uniqueStrict()->count() > 1) {
                        $group['errors'][] = 'Status hubungan atau penempatan yang sama berbeda antarbaris. Tentukan satu status sebelum import.';
                    }
                }
            }
            if ($first['item_code'] !== '' && ! in_array($group['action'], ['match', 'skip'], true)) {
                $group['errors'][] = 'Baris dengan ITEM_CODE harus memperbarui barang tersebut. Kosongkan ITEM_CODE hanya untuk barang baru.';
            }
            if ($group['action'] === 'match') {
                $target = $this->items->get($group['target_code']);
                if (! $target) {
                    $group['errors'][] = 'Kode barang tujuan tidak ditemukan.';
                } elseif ($target->ownership_type->value !== $first['ownership_type']) {
                    $group['errors'][] = 'Kepemilikan barang tujuan dikunci dan tidak boleh diubah.';
                }
                if (isset($matched[$group['target_code']])) {
                    $group['errors'][] = 'Kode tujuan juga dipakai kelompok lain. Satukan identitas sumber terlebih dahulu agar perubahan tidak saling menimpa.';
                }
                $matched[$group['target_code']] = $group['id'];
            }
            if ($first['ownership_type'] === 'CONSIGNMENT' && $group['action'] !== 'skip') {
                $active = [];
                $target = $group['action'] === 'match' ? $this->items->get($group['target_code']) : null;
                if ($target) {
                    foreach ($target->vendorItems as $relation) {
                        $vendor = $this->vendors->firstWhere('id', $relation->vendor_id);
                        if ($vendor) {
                            $active[$vendor->vendor_code] = $relation->is_active;
                        }
                    }
                }
                foreach ($unique as $row) {
                    $active[$row['vendor_code']] = $row['relation_active'];
                }
                if (! in_array(true, $active, true)) {
                    $group['errors'][] = 'CONSIGNMENT harus mempunyai minimal satu hubungan Vendor aktif.';
                }
                if ($group['action'] === 'split' && collect($unique)->contains(fn ($row) => ! $row['relation_active'])) {
                    $group['errors'][] = 'Setiap barang consignment baru harus mempunyai hubungan Vendor aktif.';
                }
            }
            $group['errors'] = array_values(array_unique($group['errors']));
            $group['state'] = $group['action'] === 'skip' ? 'skipped' : ($group['errors'] ? 'invalid' : ($group['action'] === 'review' ? 'review' : 'valid'));
            $summary['skipped_rows'] += $group['state'] === 'skipped' ? count($group['rows']) : count(array_filter($group['rows'], fn ($row) => $row['skip'])) + $duplicates;
            if ($group['state'] === 'valid') {
                $summary['ready_groups']++;
                $summary[$group['action'] === 'match' ? 'updated_items' : 'new_items'] += $group['action'] === 'split' ? count($unique) : 1;
                foreach ($unique as $row) {
                    if ($row['ownership_type'] === 'CONSIGNMENT' && ! $row['vendor_id']) {
                        $newVendors[$row['vendor_code']] = true;
                    }
                    if ($row['location_code'] !== '' && ! $row['location_id']) {
                        $newLocations[$row['location_code']] = true;
                    }
                }
            } elseif ($group['state'] !== 'skipped') {
                $summary['blocking_groups']++;
            }
            unset($group['decision']);
        }
        unset($group);

        return ['summary' => [...$summary, 'new_vendors' => count($newVendors), 'new_locations' => count($newLocations)], 'groups' => array_values($groups), 'new_vendors' => array_keys($newVendors), 'new_locations' => array_keys($newLocations)];
    }

    private function normalize(int $number, array $values, array $issues, ?Item $target, array $options): array
    {
        $unitCode = mb_strtoupper(trim($values['UOM'] ?? ''));
        $unit = isset($options['unit_map'][$unitCode]) ? $this->units->firstWhere('id', $options['unit_map'][$unitCode]) : $this->units->get($unitCode);
        $categoryCode = mb_strtoupper(trim($values['CATEGORY_CODE'] ?? ''));
        $category = $categoryCode === '' ? $target?->category : $this->categories->get($categoryCode);
        $ownership = $this->ownership($values['MATERIAL_TYPE'] ?? '');
        $vendorCode = mb_strtoupper(trim($values['VENDOR'] ?? ''));
        $locationCode = mb_strtoupper(trim($values['LOCATION'] ?? ''));
        if ($locationCode === 'CARI LOKASI' || ($locationCode === '' && ($ownership === 'CONSIGNMENT' || ! $target))) {
            $locationCode = Location::UNASSIGNED;
        }
        $minimum = trim($values['MINIMUM_STOCK'] ?? '');
        if ($minimum === '') {
            $minimum = (string) ($target?->minimum_stock ?? $options['minimum_stock'] ?? '0');
        } elseif (preg_match('/^\d+,\d{1,4}$/', $minimum)) {
            $minimum = str_replace(',', '.', $minimum);
        }
        $row = ['number' => $number, 'values' => $values, 'errors' => $issues,
            'item_code' => mb_strtoupper(trim($values['ITEM_CODE'] ?? '')), 'part_number' => trim($values['PART_NUMBER'] ?? ''), 'item_name' => trim($values['DESCRIPTION'] ?? ''),
            'ownership_type' => $ownership, 'unit_code' => $unit?->code ?? $unitCode, 'unit_id' => $unit?->id,
            'category_code' => $category?->code ?? $categoryCode, 'category_id' => $category?->id,
            'minimum_stock' => $minimum, 'is_active' => $this->boolean($values['IS_ACTIVE'] ?? '', $target?->is_active ?? true),
            'tracking_type' => mb_strtoupper(trim($values['TRACKING_TYPE'] ?? '')) ?: 'QUANTITY',
            'vendor_code' => $ownership === 'STOCK' ? '' : $vendorCode, 'vendor_name' => trim($values['VENDOR'] ?? ''), 'vendor_id' => null,
            'location_code' => $locationCode, 'location_id' => null, 'relation_active' => $this->boolean($values['RELATION_ACTIVE'] ?? '', true)];
        $validator = Validator::make($row, ['part_number' => ['required', 'string', 'max:100'], 'item_name' => ['required', 'string', 'max:200'],
            'ownership_type' => ['in:STOCK,CONSIGNMENT'], 'minimum_stock' => ['numeric', 'min:0', 'decimal:0,4', 'max:99999999999999.9999'],
            'is_active' => ['boolean'], 'relation_active' => ['boolean'], 'tracking_type' => ['in:QUANTITY'], 'vendor_code' => ['max:50'], 'location_code' => ['max:50']], [
                'part_number.required' => 'Part number wajib diisi.', 'part_number.max' => 'Part number maksimal 100 karakter.',
                'item_name.required' => 'Nama atau deskripsi wajib diisi.', 'item_name.max' => 'Nama atau deskripsi maksimal 200 karakter.',
                'ownership_type.in' => 'Jenis harus STOCK atau CONSIGNMENT.',
                'minimum_stock.numeric' => 'Target refill harus berupa angka.', 'minimum_stock.min' => 'Target refill tidak boleh negatif.',
                'minimum_stock.decimal' => 'Target refill maksimal empat desimal.', 'minimum_stock.max' => 'Target refill melampaui kapasitas angka yang tersedia.',
                'is_active.boolean' => 'Status barang harus 1 atau 0.', 'relation_active.boolean' => 'Status hubungan atau penempatan harus 1 atau 0.',
                'tracking_type.in' => 'Cara pelacakan yang tersedia adalah QUANTITY.',
                'vendor_code.max' => 'Kode Vendor maksimal 50 karakter.', 'location_code.max' => 'Kode lokasi maksimal 50 karakter.',
            ]);
        foreach ($validator->errors()->messages() as $field => $messages) {
            $row['errors'][$field] = $messages[0];
        }
        if (! $unit) {
            $row['errors']['UOM'] = 'Satuan '.$unitCode.' belum terdaftar. Pilih pemetaan satuan atau tambahkan Master Satuan.';
        } elseif (! $unit->is_active && $target?->unit_id !== $unit->id) {
            $row['errors']['UOM'] = 'Satuan harus aktif untuk pemilihan baru.';
        }
        if ($categoryCode !== '' && ! $category) {
            $row['errors']['CATEGORY_CODE'] = 'Kategori '.$categoryCode.' belum terdaftar. Tambahkan Master Kategori atau pilih kode kategori yang benar.';
        } elseif ($category && ! $category->is_active && ($target?->category_id !== $category->id || ($target && ! $target->is_active && $row['is_active'] === true))) {
            $row['errors']['CATEGORY_CODE'] = 'Kategori harus aktif untuk pemilihan baru.';
        }
        if ($row['item_code'] !== '' && ! $this->items->has($row['item_code'])) {
            $row['errors']['ITEM_CODE'] = 'ITEM_CODE tidak ditemukan. Gunakan kode dari export, atau kosongkan untuk barang baru.';
        }
        if (str_contains($locationCode, '&') || preg_match('/\d(?:\.\d+)?E[+-]\d+/i', $locationCode)) {
            $row['errors']['LOCATION'] = 'Lokasi harus satu kode valid; kode gabungan atau eksponensial perlu diperbaiki.';
        }
        if ($ownership === 'STOCK' && ! in_array($vendorCode, ['', 'WAREHOUSE'], true)) {
            $row['errors']['VENDOR'] = 'STOCK adalah milik internal. Kosongkan Vendor atau gunakan WAREHOUSE sebagai penanda sumber.';
        }
        if ($ownership === 'CONSIGNMENT') {
            $vendor = $this->vendors->get($vendorCode);
            $row['vendor_id'] = $vendor?->id;
            if ($vendorCode === '' || $vendorCode === 'WAREHOUSE') {
                $row['errors']['VENDOR'] = 'CONSIGNMENT wajib mempunyai Vendor yang sebenarnya.';
            } elseif (! $vendor && ! ($options['create_missing'] ?? false)) {
                $row['errors']['VENDOR'] = 'Vendor '.$vendorCode.' belum terdaftar. Aktifkan pembuatan master baru atau tambahkan Vendor.';
            } elseif ($vendor && ! $vendor->is_active && ! $target?->vendorItems->contains('vendor_id', $vendor->id)) {
                $row['errors']['VENDOR'] = 'Vendor nonaktif tidak dapat dipilih untuk hubungan baru.';
            }
        }
        if ($locationCode !== '') {
            $location = $this->locations->get($locationCode);
            $row['location_id'] = $location?->id;
            if (! $location && ! ($options['create_missing'] ?? false)) {
                $row['errors']['LOCATION'] = 'Lokasi '.$locationCode.' belum terdaftar. Aktifkan pembuatan master baru atau tambahkan Lokasi.';
            } elseif ($location && ! $location->is_active && ! ($target?->vendorItems->contains('location_id', $location->id) || $target?->stockItemLocations->contains('location_id', $location->id))) {
                $row['errors']['LOCATION'] = 'Lokasi nonaktif tidak dapat dipilih untuk penempatan baru.';
            }
        }

        return $row;
    }

    private function ownership(string $value): string
    {
        $value = mb_strtoupper(trim($value));

        return $value === 'CONSIGMENT' ? 'CONSIGNMENT' : $value;
    }

    private function boolean(string $value, bool $default): mixed
    {
        return match (mb_strtoupper(trim($value))) {
            '' => $default, '1', 'TRUE', 'AKTIF' => true, '0', 'FALSE', 'NONAKTIF' => false, default => $value,
        };
    }
}
