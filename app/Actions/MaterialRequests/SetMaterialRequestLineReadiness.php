<?php

namespace App\Actions\MaterialRequests;

use App\Models\MaterialRequest;
use App\Models\MaterialRequestLine;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SetMaterialRequestLineReadiness
{
    public function handle(MaterialRequestLine $line, User $warehouse, string $status): MaterialRequestLine
    {
        if (! $warehouse->isWarehouse() || ! in_array($status, ['PREPARING', 'READY'], true)) {
            throw ValidationException::withMessages(['readiness' => 'Hanya Warehouse dapat mengubah kesiapan menjadi PREPARING atau READY.']);
        }

        return DB::transaction(function () use ($line, $status): MaterialRequestLine {
            $locked = MaterialRequestLine::query()->lockForUpdate()->findOrFail($line->id);
            $request = MaterialRequest::query()->lockForUpdate()->findOrFail($locked->material_request_id);
            if (in_array($request->status, ['PARTIALLY_ISSUED', 'COMPLETED', 'CANCELLED'], true) || in_array($locked->status, ['PARTIALLY_ISSUED', 'ISSUED', 'CANCELLED'], true)) {
                throw ValidationException::withMessages(['readiness' => 'Baris yang sudah diserahkan atau ditutup tidak dapat diubah.']);
            }
            $locked->update(['status' => $status]);
            $statuses = $request->lines()->pluck('status');
            $request->update(['status' => $statuses->every(fn ($value) => $value === 'READY') ? 'READY' : ($statuses->contains('READY') ? 'PARTIALLY_READY' : 'PREPARING')]);

            return $locked->fresh();
        });
    }
}
