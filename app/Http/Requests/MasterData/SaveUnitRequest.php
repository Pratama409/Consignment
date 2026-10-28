<?php

namespace App\Http\Requests\MasterData;

use App\Models\Unit;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class SaveUnitRequest extends FormRequest
{
    public function authorize(): bool
    {
        $unit = $this->route('unit');

        return $unit instanceof Unit
            ? ($this->user()?->can('update', $unit) ?? false)
            : ($this->user()?->can('create', Unit::class) ?? false);
    }

    protected function prepareForValidation(): void
    {
        foreach (['code', 'name'] as $field) {
            if (is_string($this->input($field))) {
                $value = trim($this->input($field));
                $this->merge([$field => $field === 'code' ? Str::upper($value) : $value]);
            }
        }
    }

    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'max:30', Rule::unique('units', 'code')->ignore($this->route('unit'))],
            'name' => ['required', 'string', 'max:100'],
            'is_active' => ['required', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'code.required' => 'Kode satuan wajib diisi.',
            'code.max' => 'Kode satuan maksimal 30 karakter.',
            'code.unique' => 'Kode satuan sudah digunakan, termasuk pada satuan nonaktif.',
            'name.required' => 'Nama satuan wajib diisi.',
            'name.max' => 'Nama satuan maksimal 100 karakter.',
            'is_active.required' => 'Tentukan status satuan.',
            'is_active.boolean' => 'Status satuan tidak valid.',
        ];
    }
}
