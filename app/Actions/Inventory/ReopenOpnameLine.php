<?php

namespace App\Actions\Inventory;

use App\Actions\Audit\WriteAuditLog;
use App\Enums\StockAdjustmentStatus;
use App\Enums\StockOpnameStatus;
use App\Models\StockOpnameLine;
use App\Models\StockOpnameSession;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ReopenOpnameLine
{
    public function __construct(private WriteAuditLog $audit) {}

    /**
     * Sends a line whose Koreksi Stok was rejected back to counting. The system snapshot is kept: a frozen
     * bucket cannot move, so the snapshot stays valid and the bucket stays frozen until the recount resolves.
     */
    public function handle(StockOpnameLine $line, User $admin): StockOpnameLine
    {
        if (! $admin->isAdmin() || ! $admin->is_active) {
            throw ValidationException::withMessages(['authorization' => 'Hanya Admin aktif yang dapat meminta hitung ulang.']);
        }

        return DB::transaction(function () use ($line, $admin): StockOpnameLine {
            $session = StockOpnameSession::whereKey($line->stock_opname_session_id)->lockForUpdate()->firstOrFail();
            $record = StockOpnameLine::whereKey($line->id)->lockForUpdate()->firstOrFail();
            if ($session->status !== StockOpnameStatus::Resolving || $record->adjustment?->status !== StockAdjustmentStatus::Rejected) {
                throw ValidationException::withMessages(['line' => 'Hitung ulang hanya untuk baris yang penyesuaiannya ditolak.']);
            }
            $before = $record->toArray();
            $record->update(['counted_quantity' => null, 'counted_at' => null, 'counted_by' => null, 'stock_adjustment_id' => null]);
            $session->update(['status' => StockOpnameStatus::Counting, 'approved_by' => null, 'approved_at' => null, 'submitted_at' => null]);
            $this->audit->handle($admin, 'stock_opname.line_reopened', $record, $before, $record->fresh()->toArray());

            return $record->fresh();
        });
    }
}
