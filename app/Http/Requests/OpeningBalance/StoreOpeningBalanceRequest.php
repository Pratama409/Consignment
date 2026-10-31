<?php

namespace App\Http\Requests\OpeningBalance;

use Illuminate\Foundation\Http\FormRequest;

class StoreOpeningBalanceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isAdmin() ?? false;
    }

    public function rules(): array
    {
        return [
            'file' => ['nullable', 'required_without:rows', 'file', 'mimes:xlsx', 'max:5120'],
            'cutoff_at' => ['required', 'date_format:Y-m-d\TH:i'],
            'rows' => ['nullable', 'required_without:file', 'array', 'min:1', 'max:5000'],
            'rows.*.row_number' => ['nullable', 'integer', 'min:1'],
            'rows.*.item_code' => ['required_with:rows', 'string', 'max:100'],
            'rows.*.location_code' => ['required_with:rows', 'string', 'max:100'],
            'rows.*.vendor_code' => ['nullable', 'string', 'max:100'],
            'rows.*.quantity' => ['required_with:rows', 'string', 'max:30'],
        ];
    }
}
