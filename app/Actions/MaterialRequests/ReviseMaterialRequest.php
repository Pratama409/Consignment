<?php

namespace App\Actions\MaterialRequests;

use App\Models\MaterialRequest;
use App\Models\MaterialRequestLine;
use App\Support\WholeQuantity;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ReviseMaterialRequest
{
    /** @param list<array{line_id:string,quantity:string|int|float,notes?:string|null}> $lines */
    public function handle(MaterialRequest $request, string $reason, array $lines): MaterialRequest
    {
        if (trim($reason) === '' || $lines === []) {
            throw ValidationException::withMessages(['revision' => 'Alasan dan perubahan quantity wajib diisi.']);
        }

        return DB::transaction(function () use ($request, $reason, $lines): MaterialRequest {
            $locked = MaterialRequest::query()->lockForUpdate()->findOrFail($request->id);
            if (in_array($locked->status, ['PARTIALLY_ISSUED', 'COMPLETED', 'CANCELLED'], true) || $locked->issues()->where('status', 'POSTED')->exists()) {
                throw ValidationException::withMessages(['revision' => 'MR yang sudah diserahkan atau ditutup tidak dapat direvisi.']);
            }
            $revisionNo = (int) $locked->revisions()->max('revision_no') + 1;
            $revision = $locked->revisions()->create(['revision_no' => $revisionNo, 'reason' => trim($reason), 'revised_at' => now()]);
            $seen = [];
            foreach ($lines as $input) {
                $line = MaterialRequestLine::query()->where('material_request_id', $locked->id)->whereKey($input['line_id'] ?? '')->lockForUpdate()->first();
                if (! $line || isset($seen[$line->id]) || ! WholeQuantity::isPositive($input['quantity'] ?? null)) {
                    throw ValidationException::withMessages(['revision' => 'Baris revisi harus valid, unik, dan berupa satuan bulat positif.']);
                }
                $seen[$line->id] = true;
                $revision->lines()->create(['material_request_line_id' => $line->id, 'revised_quantity' => $input['quantity'], 'notes' => $input['notes'] ?? null]);
            }
            $locked->approvals()->where('status', 'PENDING')->update(['status' => 'SUPERSEDED']);
            $locked->approvals()->create(['revision_no' => $revisionNo, 'status' => 'PENDING']);

            return $locked->fresh(['lines', 'revisions.lines', 'approvals']);
        });
    }
}
