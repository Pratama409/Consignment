<?php

namespace App\Http\Requests\UserManagement;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->route('user')
            ? $this->user()->can('update', $this->route('user'))
            : $this->user()->can('create', User::class);
    }

    public static function userRules(?User $user = null): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users')->ignore($user?->id)],
            'password' => [$user ? 'nullable' : 'required', 'string', 'min:8', 'max:255'],
            'role' => ['required', Rule::enum(UserRole::class)],
            'vendor_id' => ['nullable', 'required_if:role,vendor', 'prohibited_unless:role,vendor', 'uuid', 'exists:vendors,id'],
            'employee_no' => ['nullable', 'string', 'max:50'],
            'position' => ['nullable', 'string', 'max:150'],
            'is_active' => ['required', 'boolean'],
        ];
    }

    public function rules(): array
    {
        return $this->isMethod('patch') ? ['is_active' => ['required', 'boolean']] : self::userRules($this->route('user'));
    }

    public function attributes(): array
    {
        return ['name' => 'nama pengguna', 'email' => 'email', 'password' => 'kata sandi', 'role' => 'role', 'vendor_id' => 'Vendor'];
    }
}
