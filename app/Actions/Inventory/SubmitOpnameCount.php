<?php

namespace App\Actions\Inventory;

use App\Actions\Audit\WriteAuditLog;
use App\Enums\StockOpnameStatus;
use App\Models\StockBalance;
use App\Models\StockOpnameSession;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SubmitOpnameCount
{
    public function __construct(private WriteAuditLog $audit) {}

    public function handle(StockOpnameSession $session, User $warehouse): StockOpnameSession
    {
        if (! $warehouse->isWarehouse() || ! $warehouse->is_active) {
            throw ValidationException::withMessages(['authorization' => 'Hanya Warehouse aktif yang dapat mengirim hasil hitung.']);
        }

        return DB::transaction(function () use ($session, $warehouse): StockOpnameSession {
            $record = StockOpnameSession::whereKey($session->id)->lockForUpdate()->firstOrFail();
            if ($record->status !== StockOpnameStatus::Counting) {
                throw ValidationException::withMessages(['session' => 'Hanya sesi COUNTING yang dapat dikirim.']);
            }
            $lines = $record->lines()->orderBy('stock_bucket_id')->lockForUpdate()->get();
            if ($lines->isEmpty() || $lines->contains(fn ($line) => $line->counted_quantity === null)) {
                throw ValidationException::withMessages(['session' => 'Semua baris harus sudah dihitung sebelum dikirim.']);
            }
            foreach ($lines as $line) {
                // Same lock a posting takes on the balance, so a posting lands before this snapshot or sees the freeze.
                $balance = StockBalance::where('stock_bucket_id', $line->stock_bucket_id)->lockForUpdate()->first();
                if ($line->system_quantity === null) {
                    $line->update(['system_quantity' => $balance?->quantity ?? '0.0000']);
                }
            }
            $before = $record->toArray();
            $record->update(['status' => StockOpnameStatus::Counted, 'counted_by' => $warehouse->id, 'submitted_at' => now()]);
            $this->audit->handle($warehouse, 'stock_opname.submitted', $record, $before, $record->fresh()->toArray());

            return $record->fresh();
        });
    }
}
