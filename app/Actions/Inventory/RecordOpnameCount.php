<?php

namespace App\Actions\Inventory;

use App\Actions\Audit\WriteAuditLog;
use App\Enums\StockOpnameStatus;
use App\Models\StockOpnameLine;
use App\Models\StockOpnameSession;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RecordOpnameCount
{
    public function __construct(private WriteAuditLog $audit) {}

    public function handle(StockOpnameLine $line, string $quantity, User $warehouse): StockOpnameLine
    {
        if (! $warehouse->isWarehouse() || ! $warehouse->is_active) {
            throw ValidationException::withMessages(['authorization' => 'Hanya Warehouse aktif yang dapat memasukkan hasil hitung.']);
        }
        $quantity = trim($quantity);
        if (preg_match('/^\d{1,14}(\.\d{1,4})?$/', $quantity) !== 1) {
            throw ValidationException::withMessages(['quantity' => 'Quantity hitung harus angka nol atau lebih dengan maksimal 4 desimal.']);
        }

        return DB::transaction(function () use ($line, $quantity, $warehouse): StockOpnameLine {
            $session = StockOpnameSession::whereKey($line->stock_opname_session_id)->lockForUpdate()->firstOrFail();
            if ($session->status !== StockOpnameStatus::Counting) {
                throw ValidationException::withMessages(['session' => 'Hasil hitung hanya dapat diubah saat sesi berstatus COUNTING.']);
            }
            $record = StockOpnameLine::whereKey($line->id)->lockForUpdate()->firstOrFail();
            if ($record->system_quantity !== null && $record->counted_quantity !== null) {
                throw ValidationException::withMessages(['line' => 'Hitungan baris ini sudah dikirim dan tidak dapat diubah kecuali dibuka untuk hitung ulang.']);
            }
            $before = $record->toArray();
            // counted_at marks the first count of the round, so the movement log still covers a re-saved count.
            $record->update(['counted_quantity' => bcadd($quantity, '0', 4), 'counted_by' => $warehouse->id, 'counted_at' => $record->counted_at ?? now()]);
            $this->audit->handle($warehouse, 'stock_opname.counted', $record, $before, $record->fresh()->toArray());

            return $record->fresh();
        });
    }
}
