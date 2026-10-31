<?php

namespace App\Queries;

use App\Models\Item;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

class ItemListQuery
{
    public function make(array $filters, ?User $actor = null): Builder
    {
        if ($actor?->isVendor()) {
            abort_if(! empty($filters['vendor']) && $filters['vendor'] !== $actor->vendor_id, 403);
            $filters['vendor'] = $actor->vendor_id;
        }
        $query = Item::query();
        if (trim($filters['q'] ?? '') !== '') {
            $term = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], trim($filters['q'])).'%';
            $reference = fn ($query, $code, $name) => $query->where(fn ($query) => $query->whereRaw("{$code} LIKE ? ESCAPE '!'", [$term])->orWhereRaw("{$name} LIKE ? ESCAPE '!'", [$term]));
            $query->where(fn ($query) => $query->whereRaw("item_code LIKE ? ESCAPE '!'", [$term])->orWhereRaw("part_number LIKE ? ESCAPE '!'", [$term])->orWhereRaw("item_name LIKE ? ESCAPE '!'", [$term])
                ->orWhereHas('category', fn ($query) => $reference($query, 'code', 'name'))
                ->orWhereHas('vendorItems', fn ($query) => $query->active()->when($actor?->isVendor(), fn ($query) => $query->where('vendor_id', $actor->vendor_id))->where(fn ($query) => $query
                    ->whereHas('vendor', fn ($query) => $reference($query, 'vendor_code', 'vendor_name'))
                    ->orWhereHas('location', fn ($query) => $reference($query, 'location_code', 'location_name'))))
                ->orWhereHas('stockItemLocations', fn ($query) => $query->active()->whereHas('location', fn ($query) => $reference($query, 'location_code', 'location_name'))));
        }
        if (($filters['status'] ?? 'all') !== 'all') {
            $query->where('is_active', $filters['status'] === 'active');
        }
        if (($filters['ownership'] ?? 'all') !== 'all') {
            $query->where('ownership_type', $filters['ownership']);
        }
        if (($filters['category'] ?? 'all') === 'uncategorized') {
            $query->whereNull('category_id');
        } elseif (! in_array($filters['category'] ?? 'all', ['all', '', null], true)) {
            $query->where('category_id', $filters['category']);
        }
        if (! empty($filters['vendor'])) {
            $query->whereHas('vendorItems', fn ($query) => $query->active()->where('vendor_id', $filters['vendor'])
                ->when(! empty($filters['location']), fn ($query) => $query->where('location_id', $filters['location'])));
        } elseif (! empty($filters['location'])) {
            $query->where(fn ($query) => $query->whereHas('vendorItems', fn ($query) => $query->active()->where('location_id', $filters['location']))
                ->orWhereHas('stockItemLocations', fn ($query) => $query->active()->where('location_id', $filters['location'])));
        }

        return $query->orderBy($filters['sort'] ?? 'item_code', $filters['direction'] ?? 'asc')->orderBy('id');
    }
}
