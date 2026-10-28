<?php

namespace App\Http\Requests\MasterData;

use Illuminate\Foundation\Http\FormRequest;

class UpdateUnitStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('unit')) ?? false;
    }

    public function rules(): array
    {
        return ['is_active' => ['required', 'boolean']];
    }

    public function messages(): array
    {
        return ['is_active.required' => 'Tentukan status satuan.', 'is_active.boolean' => 'Status satuan tidak valid.'];
    }
}
