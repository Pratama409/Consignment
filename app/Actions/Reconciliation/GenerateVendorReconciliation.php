<?php

namespace App\Actions\Reconciliation;

use App\Models\User;
use App\Models\Vendor;
use App\Models\VendorReconciliation;
use App\Queries\VendorReconciliationSourceQuery;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class GenerateVendorReconciliation
{
    public function __construct(private VendorReconciliationSourceQuery $source) {}

    public function handle(Vendor $vendor, CarbonImmutable $start, CarbonImmutable $end, User $admin): VendorReconciliation
    {
        if (! $admin->isAdmin() || $end->lt($start)) {
            throw ValidationException::withMessages(['reconciliation' => 'Hanya Admin dapat membuat BA untuk periode yang valid.']);
        }

        return DB::transaction(function () use ($vendor, $start, $end, $admin): VendorReconciliation {
            Vendor::query()->lockForUpdate()->findOrFail($vendor->id);
            $previous = VendorReconciliation::where('vendor_id', $vendor->id)->whereDate('period_start', $start->toDateString())->whereDate('period_end', $end->toDateString())->latest('version')->first();
            if ($previous && $previous->status->value !== 'FINAL') {
                throw ValidationException::withMessages(['reconciliation' => 'Masih ada BA aktif untuk Vendor dan periode ini.']);
            }
            $version = (int) ($previous?->version ?? 0) + 1;
            $ba = VendorReconciliation::create(['reconciliation_no' => sprintf('BA/%s/%s/%02d', $vendor->vendor_code, $start->format('Ym'), $version), 'vendor_id' => $vendor->id, 'period_start' => $start->toDateString(), 'period_end' => $end->toDateString(), 'version' => $version, 'status' => 'DRAFT', 'supersedes_id' => $previous?->id, 'source_cutoff_at' => now(), 'created_by' => $admin->id]);
            foreach ($this->source->forPeriod($vendor, $start, $end) as $row) {
                $ba->lines()->create(['vendor_item_id' => $row['vendor_item_id'], 'item_id' => $row['item_id'], 'location_id' => $row['location_id'], 'opening_quantity' => $row['opening'], 'in_quantity' => $row['in'], 'out_quantity' => $row['out'], 'adjustment_quantity' => $row['adjustment'], 'system_closing_quantity' => $row['closing'], 'references' => ['transaction_ids' => $row['transaction_ids']]]);
            }

            return $ba->load('lines');
        });
    }
}
