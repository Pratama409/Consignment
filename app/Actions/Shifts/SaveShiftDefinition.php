<?php

namespace App\Actions\Shifts;

use App\Models\OperationalShift;
use App\Models\ShiftDefinition;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class SaveShiftDefinition
{
    public function handle(array $data, User $admin): ShiftDefinition
    {
        if (! $admin->isAdmin() || ! $admin->is_active) {
            throw new AuthorizationException;
        }

        $data = Validator::make($data, [
            'code' => ['required', Rule::in(['DAY', 'NIGHT'])],
            'name' => ['required', 'string', 'max:100'],
            'start_time' => ['required', 'date_format:H:i'],
            'end_time' => ['required', 'date_format:H:i'],
            'effective_from' => ['required', 'date_format:Y-m-d', 'after:'.CarbonImmutable::now(ShiftDefinition::TIMEZONE)->toDateString()],
        ], ['effective_from.after' => 'Pilih tanggal mendatang dalam WITA.'])->validate();

        $dayStart = $data[$data['code'] === 'DAY' ? 'start_time' : 'end_time'];
        $nightStart = $data[$data['code'] === 'DAY' ? 'end_time' : 'start_time'];
        if ($dayStart >= $nightStart) {
            throw ValidationException::withMessages(['start_time' => 'Shift DAY harus mulai sebelum NIGHT; shift NIGHT melewati tengah malam.', 'end_time' => 'Jam mulai dan selesai harus membentuk dua shift berurutan.']);
        }

        return DB::transaction(function () use ($data, $admin, $dayStart, $nightStart): ShiftDefinition {
            $definitions = ShiftDefinition::orderBy('code')->orderBy('effective_from')->lockForUpdate()->get();
            if ($definitions->isEmpty() || $definitions->max(fn (ShiftDefinition $definition) => $definition->effective_from->toDateString()) >= $data['effective_from']) {
                throw ValidationException::withMessages(['effective_from' => 'Tanggal harus setelah versi terakhir yang tersimpan.']);
            }

            $previousDay = $definitions->last(fn (ShiftDefinition $definition) => $definition->code === 'DAY');
            $transition = CarbonImmutable::parse($data['effective_from'], ShiftDefinition::TIMEZONE)
                ->setTimeFromTimeString(min($dayStart, substr($previousDay->start_time, 0, 5)))->utc();
            // Include the previous night ending exactly at the transition boundary.
            if (OperationalShift::where('ends_at', '>=', $transition->format('Y-m-d H:i:s'))->exists()) {
                throw ValidationException::withMessages(['effective_from' => 'Tanggal ini memengaruhi shift tersimpan. Pilih tanggal yang lebih jauh.']);
            }

            $saved = null;
            foreach (['DAY', 'NIGHT'] as $code) {
                $previous = $definitions->last(fn (ShiftDefinition $definition) => $definition->code === $code);
                $version = ShiftDefinition::create([
                    'code' => $code,
                    'name' => $code === $data['code'] ? $data['name'] : $previous->name,
                    'start_time' => $code === 'DAY' ? $dayStart : $nightStart,
                    'end_time' => $code === 'DAY' ? $nightStart : $dayStart,
                    'effective_from' => $data['effective_from'],
                    'created_by' => $admin->id,
                ]);
                if ($code === $data['code']) {
                    $saved = $version;
                }
            }

            return $saved;
        }, 3);
    }
}
