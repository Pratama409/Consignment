<?php

namespace App\Actions\Inventory;

use App\Actions\Audit\WriteAuditLog;
use App\Enums\StockOpnameStatus;
use App\Models\StockOpnameSession;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CancelStockOpname
{
    public function __construct(private WriteAuditLog $audit) {}

    /** The reason is kept in the audit log; a cancelled session no longer freezes its buckets. */
    public function handle(StockOpnameSession $session, User $admin, string $reason): StockOpnameSession
    {
        if (! $admin->isAdmin() || ! $admin->is_active || trim($reason) === '') {
            throw ValidationException::withMessages(['cancel' => 'Pembatalan membutuhkan Admin aktif dan alasan.']);
        }

        return DB::transaction(function () use ($session, $admin, $reason): StockOpnameSession {
            $record = StockOpnameSession::whereKey($session->id)->lockForUpdate()->firstOrFail();
            if (! in_array($record->status, [StockOpnameStatus::Counting, StockOpnameStatus::Counted], true)) {
                throw ValidationException::withMessages(['session' => 'Sesi hanya dapat dibatalkan saat COUNTING atau COUNTED.']);
            }
            $before = $record->toArray();
            $record->update(['status' => StockOpnameStatus::Cancelled]);
            $this->audit->handle($admin, 'stock_opname.cancelled', $record, $before, $record->fresh()->toArray(), trim($reason));

            return $record->fresh();
        });
    }
}
