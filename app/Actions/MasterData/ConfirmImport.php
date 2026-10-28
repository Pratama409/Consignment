<?php

namespace App\Actions\MasterData;

use App\Models\Item;
use App\Models\Location;
use App\Models\MasterDataImport;
use App\Models\Vendor;
use App\Services\MasterData\ImportPlanner;
use App\Support\ItemPayload;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ConfirmImport
{
    public function handle(MasterDataImport $import, bool $acknowledgeSkipped): MasterDataImport
    {
        return DB::transaction(function () use ($import, $acknowledgeSkipped) {
            $batch = MasterDataImport::query()->lockForUpdate()->findOrFail($import->id);
            if ($batch->status === 'completed') {
                return $batch;
            }
            if ($batch->expires_at->isPast()) {
                throw ValidationException::withMessages(['confirmation' => 'Preview kedaluwarsa. Unggah file kembali.']);
            }
            $plan = app(ImportPlanner::class)->build($batch);
            if ($plan['summary']['blocking_groups'] || ! $plan['summary']['ready_groups']) {
                throw ValidationException::withMessages(['confirmation' => 'Selesaikan tinjauan atau lewati kelompok bermasalah sebelum import.']);
            }
            if ($plan['summary']['skipped_rows'] > 0 && ! $acknowledgeSkipped) {
                throw ValidationException::withMessages(['acknowledge_skipped' => 'Konfirmasikan bahwa baris yang ditandai akan dilewati.']);
            }
            $result = ['created' => 0, 'updated' => 0, 'skipped_rows' => $plan['summary']['skipped_rows'], 'source_rows' => $plan['summary']['source_rows'], 'items' => []];
            foreach ($plan['groups'] as $group) {
                if ($group['state'] !== 'valid') {
                    continue;
                }
                $segments = $group['action'] === 'split' ? array_map(fn ($row) => [$row], $group['unique_rows']) : [$group['unique_rows']];
                foreach ($segments as $rows) {
                    $target = $group['action'] === 'match' ? Item::where('item_code', $group['target_code'])->lockForUpdate()->firstOrFail() : null;
                    $first = $rows[0];
                    $data = $target ? ItemPayload::from($target) : ['vendor_items' => [], 'stock_locations' => []];
                    foreach (['part_number', 'item_name', 'ownership_type', 'unit_id', 'minimum_stock', 'is_active', 'tracking_type'] as $field) {
                        $data[$field] = $first[$field];
                    }
                    $vendorRows = collect($data['vendor_items'])->keyBy('vendor_id');
                    $stockRows = collect($data['stock_locations'])->keyBy('location_id');
                    foreach ($rows as $row) {
                        $location = $row['location_code'] === '' ? null : Location::firstOrCreate(['location_code' => $row['location_code']], ['is_active' => true]);
                        if ($row['ownership_type'] === 'CONSIGNMENT') {
                            $vendor = Vendor::firstOrCreate(['vendor_code' => $row['vendor_code']], ['vendor_name' => $row['vendor_name'], 'is_active' => true]);
                            $vendorRows->put($vendor->id, ['vendor_id' => $vendor->id, 'location_id' => $location->id, 'is_active' => $row['relation_active']]);
                        } elseif ($location) {
                            $stockRows->put($location->id, ['location_id' => $location->id, 'is_active' => $row['relation_active']]);
                        }
                    }
                    $data['vendor_items'] = $vendorRows->values()->all();
                    $data['stock_locations'] = $stockRows->values()->all();
                    $item = app(SaveItem::class)->handle($data, $target);
                    $result[$target ? 'updated' : 'created']++;
                    $result['items'][] = ['id' => $item->id, 'item_code' => $item->item_code, 'item_name' => $item->item_name, 'action' => $target ? 'updated' : 'created'];
                }
            }
            $batch->update(['status' => 'completed', 'confirmed_at' => now(), 'result' => $result]);

            return $batch;
        }, attempts: 3);
    }
}
