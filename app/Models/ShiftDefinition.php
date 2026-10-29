<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Validation\ValidationException;

class ShiftDefinition extends Model
{
    use HasUuids;

    public const TIMEZONE = 'Asia/Makassar';

    protected $fillable = ['code', 'name', 'start_time', 'end_time', 'effective_from', 'created_by'];

    protected function casts(): array
    {
        return ['effective_from' => 'immutable_date'];
    }

    protected static function booted(): void
    {
        $immutable = function (): never {
            throw ValidationException::withMessages(['shift' => 'Definisi shift tersimpan tidak dapat diubah. Buat versi mendatang.']);
        };
        static::updating($immutable);
        static::deleting($immutable);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function instances(): HasMany
    {
        return $this->hasMany(OperationalShift::class);
    }
}
