<?php

namespace App\Actions\Inventory;

use App\Actions\Audit\WriteAuditLog;
use App\Enums\StockOpnameStatus;
use App\Models\StockOpnameSession;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ApproveStockOpname
{
    public function __construct(private CreateStockAdjustment $adjustments, private WriteAuditLog $audit) {}

    /** Every difference becomes a draft Koreksi Stok; the whole approval succeeds or none of it does. */
    public function handle(StockOpnameSession $session, User $admin): StockOpnameSession
    {
        if (! $admin->isAdmin() || ! $admin->is_active) {
            throw ValidationException::withMessages(['authorization' => 'Hanya Admin aktif yang dapat menyetujui Stock Opname.']);
        }

        return DB::transaction(function () use ($session, $admin): StockOpnameSession {
            $record = StockOpnameSession::whereKey($session->id)->lockForUpdate()->firstOrFail();
            if ($record->status !== StockOpnameStatus::Counted) {
                throw ValidationException::withMessages(['session' => 'Hanya sesi COUNTED yang dapat disetujui.']);
            }
            $before = $record->toArray();
            $hasDifference = false;
            foreach ($record->lines()->with('bucket.location')->orderBy('stock_bucket_id')->lockForUpdate()->get() as $line) {
                $difference = $line->difference();
                if ($difference === null || bccomp($difference, '0', 4) === 0) {
                    continue;
                }
                $hasDifference = true;
                if ($line->stock_adjustment_id !== null) {
                    continue;
                }
                $bucket = $line->bucket;
                $adjustment = $this->adjustments->handle([
                    'item_id' => $bucket->resolvedItem()->id,
                    'vendor_item_id' => $bucket->vendor_item_id,
                    'location_id' => $bucket->location_id,
                    'direction' => bccomp($difference, '0', 4) > 0 ? 'IN' : 'OUT',
                    'quantity' => ltrim($difference, '-'),
                    'reason' => 'Selisih Stock Opname '.$record->session_no,
                ], $admin);
                $line->update(['stock_adjustment_id' => $adjustment->id]);
            }
            $record->update([
                'approved_by' => $admin->id, 'approved_at' => now(),
                'status' => $hasDifference ? StockOpnameStatus::Resolving : StockOpnameStatus::Completed,
                'completed_at' => $hasDifference ? null : now(),
            ]);
            $this->audit->handle($admin, 'stock_opname.approved', $record, $before, $record->fresh()->toArray());

            return $record->fresh();
        });
    }
}
