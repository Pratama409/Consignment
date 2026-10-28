<?php

namespace App\Http\Requests\MasterData;

use App\Support\ReferenceResource;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ReferenceRequest extends FormRequest
{
    public function definition(): array
    {
        return ReferenceResource::definition($this->route('resource'));
    }

    public function authorize(): bool
    {
        $definition = $this->definition();
        if ($this->isMethod('get')) {
            return $this->user()?->can('viewAny', $definition['model']) ?? false;
        }
        if ($this->route('record')) {
            return $this->user()?->can('update', $definition['model']::findOrFail($this->route('record'))) ?? false;
        }

        return $this->user()?->can('create', $definition['model']) ?? false;
    }

    protected function prepareForValidation(): void
    {
        foreach (['code', 'name', 'pic_name'] as $field) {
            if (is_string($this->input($field))) {
                $this->merge([$field => $field === 'code' ? mb_strtoupper(trim($this->input($field))) : trim($this->input($field))]);
            }
        }
    }

    public function rules(): array
    {
        if ($this->isMethod('get')) {
            return [
                'q' => ['nullable', 'string', 'max:100'], 'status' => ['sometimes', Rule::in(['all', 'active', 'inactive'])],
                'sort' => ['sometimes', Rule::in(['code', 'name'])], 'direction' => ['sometimes', Rule::in(['asc', 'desc'])],
                'per_page' => ['sometimes', 'integer', Rule::in([10, 25, 50])], 'page' => ['sometimes', 'integer', 'min:1'],
            ];
        }
        if ($this->isMethod('patch')) {
            return ['is_active' => ['required', 'boolean']];
        }
        $definition = $this->definition();

        return [
            'code' => ['required', 'string', 'max:50', Rule::unique($definition['table'], $definition['code'])->ignore($this->route('record'))],
            'name' => [$this->route('resource') === 'vendors' ? 'required' : 'nullable', 'string', 'max:150'],
            'pic_name' => ['nullable', 'string', 'max:150'], 'is_active' => ['required', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return ['code.required' => 'Kode wajib diisi.', 'code.unique' => 'Kode sudah digunakan, termasuk pada data nonaktif.',
            'code.max' => 'Kode maksimal 50 karakter.', 'name.required' => 'Nama Vendor wajib diisi.', 'name.max' => 'Nama atau keterangan maksimal 150 karakter.',
            'pic_name.max' => 'Nama PIC maksimal 150 karakter.', 'is_active.required' => 'Tentukan status data.', 'is_active.boolean' => 'Status tidak valid.'];
    }
}
