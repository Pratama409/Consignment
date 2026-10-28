<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Validation\ValidationException;

class Unit extends MasterDataModel
{
    protected $fillable = ['code', 'name', 'is_active'];

    protected static function booted(): void
    {
        parent::booted();

        static::updating(function (self $unit): void {
            if ($unit->isDirty('is_active') && ! $unit->is_active && $unit->items()->active()->exists()) {
                throw ValidationException::withMessages(['is_active' => 'Satuan masih digunakan barang aktif.']);
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
