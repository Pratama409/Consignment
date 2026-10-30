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
            $identity = MaterialRequestLine::query()->findOrFail($line->id);
            $request = MaterialRequest::query()->lockForUpdate()->findOrFail($identity->material_request_id);
            $locked = MaterialRequestLine::query()->lockForUpdate()->findOrFail($line->id);
            if (in_array($request->status, ['COMPLETED', 'CANCELLED'], true) || in_array($locked->status, ['PARTIALLY_ISSUED', 'ISSUED', 'CANCELLED'], true)) {
                throw ValidationException::withMessages(['readiness' => 'Baris yang sudah diserahkan atau ditutup tidak dapat diubah.']);
            }
            $locked->update(['status' => $status]);
            $statuses = $request->lines()->pluck('status');
            $hasIssue = $statuses->contains(fn ($value) => in_array($value, ['PARTIALLY_ISSUED', 'ISSUED'], true));
            $request->update(['status' => $hasIssue ? 'PARTIALLY_ISSUED' : ($statuses->every(fn ($value) => $value === 'READY') ? 'READY' : ($statuses->contains('READY') ? 'PARTIALLY_READY' : 'PREPARING'))]);

            return $locked->fresh();
        });
    }
}
