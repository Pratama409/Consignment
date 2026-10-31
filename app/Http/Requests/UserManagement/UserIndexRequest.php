<?php

namespace App\Http\Requests\UserManagement;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UserIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('viewAny', User::class);
    }

    public function rules(): array
    {
        return ['q' => ['nullable', 'string', 'max:100'], 'role' => ['sometimes', Rule::in(['all', ...array_column(UserRole::cases(), 'value')])],
            'status' => ['sometimes', Rule::in(['all', 'active', 'inactive'])], 'vendor' => ['nullable', 'uuid', 'exists:vendors,id'],
            'sort' => ['sometimes', Rule::in(['name', 'email', 'role'])], 'direction' => ['sometimes', Rule::in(['asc', 'desc'])],
            'per_page' => ['sometimes', 'integer', Rule::in([10, 25, 50])], 'page' => ['sometimes', 'integer', 'min:1']];
    }
}
