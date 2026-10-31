<?php

namespace App\Models;

use App\Enums\OperationalShiftStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Validation\ValidationException;

class OperationalShift extends Model
{
    use HasUuids;

    protected $fillable = ['shift_definition_id', 'code', 'name', 'operational_date', 'timezone', 'starts_at', 'ends_at', 'status'];

    protected $attributes = ['status' => 'open', 'timezone' => ShiftDefinition::TIMEZONE];

    protected function casts(): array
    {
        return ['operational_date' => 'immutable_date', 'starts_at' => 'immutable_datetime', 'ends_at' => 'immutable_datetime', 'status' => OperationalShiftStatus::class];
    }

    protected static function booted(): void
    {
        static::updating(function (self $shift): void {
            if ($shift->isDirty(['shift_definition_id', 'code', 'name', 'operational_date', 'timezone', 'starts_at', 'ends_at'])) {
                throw ValidationException::withMessages(['shift' => 'Pengelompokan shift tersimpan tidak dapat diubah.']);
            }
        });
        static::deleting(function (): never {
            throw ValidationException::withMessages(['shift' => 'Riwayat shift tidak dapat dihapus.']);
        });
    }

    public function definition(): BelongsTo
    {
        return $this->belongsTo(ShiftDefinition::class, 'shift_definition_id');
    }
}
