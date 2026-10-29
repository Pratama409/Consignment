<?php

namespace App\Http\Requests\MasterData;

use App\Models\Category;
use App\Models\Item;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class ItemIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return ($this->user()?->can('viewAny', Item::class) ?? false)
            && (! $this->user()->isVendor() || ! $this->filled('vendor') || $this->input('vendor') === $this->user()->vendor_id);
    }

    public function rules(): array
    {
        return ['q' => ['nullable', 'string', 'max:100'], 'status' => ['sometimes', Rule::in(['all', 'active', 'inactive'])],
            'ownership' => ['sometimes', Rule::in(['all', 'STOCK', 'CONSIGNMENT'])], 'sort' => ['sometimes', Rule::in(['item_code', 'part_number', 'item_name', 'minimum_stock'])],
            'direction' => ['sometimes', Rule::in(['asc', 'desc'])], 'per_page' => ['sometimes', 'integer', Rule::in([10, 25, 50])], 'page' => ['sometimes', 'integer', 'min:1'],
            'vendor' => ['nullable', 'uuid', 'exists:vendors,id'], 'location' => ['nullable', 'uuid', 'exists:locations,id'],
            'category' => ['nullable', 'string', function ($attribute, $value, $fail) {
                if (! in_array($value, ['all', 'uncategorized'], true) && (! Str::isUuid($value) || ! Category::whereKey($value)->exists())) {
                    $fail('Filter kategori tidak valid.');
                }
            }]];
    }
}
