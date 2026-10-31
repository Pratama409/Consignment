<?php

namespace App\Http\Requests\Monitoring;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class TransactionRecapRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return ['q' => ['nullable', 'string', 'max:100'], 'from' => ['nullable', 'date'], 'to' => ['nullable', 'date', 'after_or_equal:from'], 'type' => ['nullable', Rule::in(['OPENING', 'REFILL_RECEIPT', 'MANUAL_RECEIPT', 'MATERIAL_ISSUE', 'ADJUSTMENT', 'REVERSAL'])], 'ownership' => ['nullable', Rule::in(['STOCK', 'CONSIGNMENT'])], 'vendor_id' => ['nullable', 'uuid', 'exists:vendors,id'], 'item_id' => ['nullable', 'uuid', 'exists:items,id'], 'location_id' => ['nullable', 'uuid', 'exists:locations,id'], 'per_page' => ['nullable', 'integer', Rule::in([10, 25, 50])]];
    }
}
