<?php

namespace App\Queries;

use App\Models\Item;
use Illuminate\Database\Eloquent\Builder;

class ItemListQuery
{
    public function make(array $filters): Builder
    {
        $query = Item::query();
        if (trim($filters['q'] ?? '') !== '') {
            $term = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], trim($filters['q'])).'%';
            $query->where(fn ($query) => $query->whereRaw("item_code LIKE ? ESCAPE '!'", [$term])->orWhereRaw("part_number LIKE ? ESCAPE '!'", [$term])->orWhereRaw("item_name LIKE ? ESCAPE '!'", [$term]));
        }
        if (($filters['status'] ?? 'all') !== 'all') {
            $query->where('is_active', $filters['status'] === 'active');
        }
        if (($filters['ownership'] ?? 'all') !== 'all') {
            $query->where('ownership_type', $filters['ownership']);
        }

        return $query->orderBy($filters['sort'] ?? 'item_code', $filters['direction'] ?? 'asc')->orderBy('id');
    }
}
