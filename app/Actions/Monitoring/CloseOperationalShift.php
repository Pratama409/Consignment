<?php

namespace App\Actions\Monitoring;

use App\Models\OperationalShift;
use App\Models\ShiftRecapSnapshot;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CloseOperationalShift
{
    public function __construct(private GenerateShiftRecap $generate) {}

    public function handle(OperationalShift $shift, User $actor): ShiftRecapSnapshot
    {
        if (! $actor->isAdmin() && ! $actor->isWarehouse()) {
            throw ValidationException::withMessages(['shift' => 'Hanya Admin atau Warehouse dapat menutup rekap shift.']);
        }

        return DB::transaction(function () use ($shift, $actor): ShiftRecapSnapshot {
            $locked = OperationalShift::query()->lockForUpdate()->findOrFail($shift->id);
            if ($locked->status->value === 'closed') {
                throw ValidationException::withMessages(['shift' => 'Shift ini sudah ditutup.']);
            }
            $summary = $this->generate->handle($locked);
            $version = (int) ShiftRecapSnapshot::where('operational_shift_id', $locked->id)->max('version') + 1;
            $snapshot = ShiftRecapSnapshot::create(['operational_shift_id' => $locked->id, 'version' => $version, 'summary' => $summary, 'checksum' => hash('sha256', json_encode($summary, JSON_UNESCAPED_SLASHES)), 'generated_by' => $actor->id, 'closed_at' => now()]);
            $locked->update(['status' => 'closed']);

            return $snapshot;
        });
    }
}
