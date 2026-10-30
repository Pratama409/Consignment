<?php

namespace App\Actions\MaterialRequests;

use App\Models\MaterialRequest;
use Illuminate\Validation\ValidationException;

class VerifyPublicMaterialRequest
{
    public function handle(string $requestNo, string $verifier): MaterialRequest
    {
        $request = MaterialRequest::query()->where('request_no', trim($requestNo))->first();
        $verifier = trim($verifier);
        if (! $request || $verifier === '' || (! hash_equals($request->applicant_nrp, $verifier) && ! hash_equals($request->applicant_phone, $verifier))) {
            throw ValidationException::withMessages(['tracking' => 'Kode MR atau verifikasi pemohon tidak cocok.']);
        }

        return $request->load(['department', 'lines.item', 'approvals', 'issues.lines']);
    }
}
