<?php

namespace App\Actions\MaterialRequests;

use App\Models\Department;
use App\Models\Item;
use App\Models\MaterialRequest;
use App\Support\OperationalNumberGenerator;
use App\Support\WholeQuantity;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CreateMaterialRequest
{
    public function __construct(private OperationalNumberGenerator $numbers) {}

    /** @param array{applicant_name:string,applicant_nrp:string,applicant_phone:string,department_id:string,needed_at:mixed,purpose:string} $header
     *  @param list<array{item_id:string,quantity:string|int|float,notes?:string|null}> $lines */
    public function handle(array $header, array $lines): MaterialRequest
    {
        if ($lines === [] || ! Department::query()->whereKey($header['department_id'] ?? '')->where('is_active', true)->exists()) {
            throw ValidationException::withMessages(['request' => 'Departemen aktif dan minimal satu barang wajib dipilih.']);
        }
        foreach (['applicant_name', 'applicant_nrp', 'applicant_phone', 'purpose'] as $field) {
            if (trim((string) ($header[$field] ?? '')) === '') {
                throw ValidationException::withMessages([$field => 'Data pemohon dan keperluan wajib dilengkapi.']);
            }
        }

        return DB::transaction(function () use ($header, $lines): MaterialRequest {
            $seen = [];
            foreach ($lines as $line) {
                $itemId = (string) ($line['item_id'] ?? '');
                if (isset($seen[$itemId]) || ! WholeQuantity::isPositive($line['quantity'] ?? null) || ! Item::active()->whereKey($itemId)->exists()) {
                    throw ValidationException::withMessages(['lines' => 'Barang harus aktif, unik, dan quantity berupa satuan bulat positif.']);
                }
                $seen[$itemId] = true;
            }
            $sequence = $this->numbers->next('material_request');
            $request = MaterialRequest::create([
                ...$header,
                'request_no' => sprintf('MR/%s/%04d', now()->format('Ymd'), $sequence),
                'public_token_hash' => hash('sha256', Str::random(64)),
                'status' => 'SUBMITTED',
                'submitted_at' => now(),
            ]);
            foreach ($lines as $line) {
                $request->lines()->create(['item_id' => $line['item_id'], 'requested_quantity' => $line['quantity'], 'status' => 'PENDING', 'notes' => $line['notes'] ?? null]);
            }
            $request->approvals()->create(['revision_no' => 0, 'status' => 'PENDING']);

            return $request->load(['lines.item', 'approvals']);
        });
    }
}
