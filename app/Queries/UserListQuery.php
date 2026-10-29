<?php

namespace App\Queries;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

class UserListQuery
{
    public function make(array $filters): Builder
    {
        $query = User::query()->with('vendor');
        if (trim($filters['q'] ?? '') !== '') {
            $term = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], trim($filters['q'])).'%';
            $query->where(fn ($query) => $query->whereRaw("name LIKE ? ESCAPE '!'", [$term])->orWhereRaw("email LIKE ? ESCAPE '!'", [$term])->orWhereRaw("employee_no LIKE ? ESCAPE '!'", [$term]));
        }
        if (($filters['role'] ?? 'all') !== 'all') {
            $query->where('role', $filters['role']);
        }
        if (($filters['status'] ?? 'all') !== 'all') {
            $query->where('is_active', $filters['status'] === 'active');
        }
        if (! empty($filters['vendor'])) {
            $query->where('vendor_id', $filters['vendor']);
        }

        return $query->orderBy($filters['sort'] ?? 'name', $filters['direction'] ?? 'asc')->orderBy('id');
    }
}
