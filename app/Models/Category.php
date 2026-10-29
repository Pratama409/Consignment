<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Validation\ValidationException;

class Category extends MasterDataModel
{
    protected $fillable = ['code', 'name', 'is_active'];

    protected static function booted(): void
    {
        parent::booted();
        static::updating(function (self $category): void {
            if ($category->isDirty('is_active') && ! $category->is_active && $category->items()->active()->exists()) {
                throw ValidationException::withMessages(['is_active' => 'Kategori masih digunakan barang aktif.']);
            }
        });
    }

    protected function codeColumn(): ?string
    {
        return 'code';
    }

    public function items(): HasMany
    {
        return $this->hasMany(Item::class);
    }
}
