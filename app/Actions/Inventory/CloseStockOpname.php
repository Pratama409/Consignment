<?php

namespace App\Actions\Inventory;

use App\Actions\Audit\WriteAuditLog;
use App\Enums\StockAdjustmentStatus;
use App\Enums\StockOpnameStatus;
use App\Models\StockOpnameSession;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CloseStockOpname
{
    public function __construct(private WriteAuditLog $audit) {}

    public function handle(StockOpnameSession $session, User $admin): StockOpnameSession
    {
        if (! $admin->isAdmin() || ! $admin->is_active) {
            throw ValidationException::withMessages(['authorization' => 'Hanya Admin aktif yang dapat menutup Stock Opname.']);
        }

        return DB::transaction(function () use ($session, $admin): StockOpnameSession {
            $record = StockOpnameSession::whereKey($session->id)->lockForUpdate()->firstOrFail();
            if ($record->status !== StockOpnameStatus::Resolving) {
                throw ValidationException::withMessages(['session' => 'Hanya sesi RESOLVING yang dapat ditutup.']);
            }
            $unresolved = $record->lines()->with('adjustment')->get()->filter(function ($line) {
                $difference = $line->difference();

                return $difference !== null && bccomp($difference, '0', 4) !== 0 && $line->adjustment?->status !== StockAdjustmentStatus::Posted;
            });
            if ($unresolved->isNotEmpty()) {
                throw ValidationException::withMessages(['session' => 'Masih ada '.$unresolved->count().' selisih yang penyesuaiannya belum diposting.']);
            }
            $before = $record->toArray();
            $record->update(['status' => StockOpnameStatus::Completed, 'completed_at' => now()]);
            $this->audit->handle($admin, 'stock_opname.completed', $record, $before, $record->fresh()->toArray());

            return $record->fresh();
        });
    }
}
