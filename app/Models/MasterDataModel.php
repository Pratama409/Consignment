<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

abstract class MasterDataModel extends Model
{
    protected $attributes = ['is_active' => true];

    protected static function booted(): void
    {
        static::saving(function (self $model): void {
            $column = $model->codeColumn();

            if ($column !== null && $model->getAttribute($column) !== null) {
                $model->setAttribute($column, mb_strtoupper(trim($model->getAttribute($column))));
            }
        });

        static::deleting(function (): never {
            throw ValidationException::withMessages([
                'is_active' => 'Nonaktifkan Master Data untuk mempertahankan identitas dan riwayatnya.',
            ]);
        });
    }

    protected function codeColumn(): ?string
    {
        return null;
    }

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}
