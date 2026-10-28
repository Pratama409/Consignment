<?php

namespace App\Http\Requests\MasterData;

use App\Models\Unit;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UnitIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('viewAny', Unit::class) ?? false;
    }

    public function rules(): array
    {
        return [
            'q' => ['nullable', 'string', 'max:100'],
            'status' => ['sometimes', Rule::in(['all', 'active', 'inactive'])],
            'per_page' => ['sometimes', 'integer', Rule::in([10, 25, 50])],
            'page' => ['sometimes', 'integer', 'min:1'],
            'sort' => ['sometimes', Rule::in(['code', 'name'])],
            'direction' => ['sometimes', Rule::in(['asc', 'desc'])],
        ];
    }
}
