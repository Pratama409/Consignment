<?php

namespace App\Http\Requests\Inventory;

use Illuminate\Foundation\Http\FormRequest;

class StoreStockCorrectionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return ($this->user()?->isAdmin() || $this->user()?->isWarehouse()) ?? false;
    }

    public function rules(): array
    {
        return [
            'item_id' => ['required', 'uuid', 'exists:items,id'],
            'vendor_item_id' => ['nullable', 'uuid', 'exists:vendor_items,id'],
            'location_id' => ['required', 'uuid', 'exists:locations,id'],
            'direction' => ['required', 'in:IN,OUT'],
            'quantity' => ['required', 'regex:/^(0|[1-9][0-9]{0,9})(\.[0-9]{1,4})?$/'],
            'reason' => ['required', 'string', 'max:2000'],
        ];
    }
}
