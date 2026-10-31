<?php

namespace App\Http\Requests\Reconciliation;

use Illuminate\Foundation\Http\FormRequest;

class VendorResponseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return ['agree' => ['required', 'boolean'], 'note' => ['nullable', 'required_if:agree,false', 'string', 'max:2000']];
    }
}
