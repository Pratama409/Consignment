<?php

namespace App\Actions\Inventory;

use App\Actions\Audit\WriteAuditLog;
use App\Enums\StockAdjustmentStatus;
use App\Models\StockAdjustment;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ReviewStockAdjustment
{
    public function __construct(private WriteAuditLog $audit) {}

    public function handle(StockAdjustment $adjustment, bool $approve, string $note, User $admin): StockAdjustment
    {
        if (! $admin->isAdmin() || ! $admin->is_active || trim($note) === '') {
            throw ValidationException::withMessages(['review' => 'Review membutuhkan Admin aktif dan catatan.']);
        }

        return DB::transaction(function () use ($adjustment, $approve, $note, $admin): StockAdjustment {
            $record = StockAdjustment::whereKey($adjustment->id)->lockForUpdate()->firstOrFail();
            if ($record->status !== StockAdjustmentStatus::Draft) {
                throw ValidationException::withMessages(['review' => 'Hanya draft yang dapat direview.']);
            }
            $before = $record->toArray();
            $record->update([
                'status' => $approve ? StockAdjustmentStatus::Approved : StockAdjustmentStatus::Rejected,
                'review_note' => trim($note),
                'reviewed_by' => $admin->id,
                'reviewed_at' => now(),
            ]);
            $this->audit->handle($admin, $approve ? 'stock_adjustment.approved' : 'stock_adjustment.rejected', $record, $before, $record->fresh()->toArray(), trim($note));

            return $record->fresh();
        });
    }
}
