<?php

namespace App\Http\Requests\Monitoring;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SystemStockRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return ['q' => ['nullable', 'string', 'max:100'], 'ownership' => ['nullable', Rule::in(['STOCK', 'CONSIGNMENT'])], 'vendor_id' => ['nullable', 'uuid', 'exists:vendors,id'], 'location_id' => ['nullable', 'uuid', 'exists:locations,id'], 'per_page' => ['nullable', 'integer', Rule::in([10, 25, 50])]];
    }
}
