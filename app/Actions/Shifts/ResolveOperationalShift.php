<?php

namespace App\Actions\Shifts;

use App\Models\OperationalShift;
use App\Models\ShiftDefinition;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use LogicException;

class ResolveOperationalShift
{
    public function handle(CarbonImmutable $postedAt): OperationalShift
    {
        return DB::transaction(function () use ($postedAt): OperationalShift {
            // Settings and posting take the same locks in the same order.
            $definitions = ShiftDefinition::orderBy('code')->orderBy('effective_from')->lockForUpdate()->get();
            $utc = $postedAt->utc()->format('Y-m-d H:i:s');
            $existing = OperationalShift::where('starts_at', '<=', $utc)->where('ends_at', '>', $utc)->first();
            if ($existing) {
                return $existing;
            }

            $local = $postedAt->setTimezone(ShiftDefinition::TIMEZONE);
            foreach ([$local->startOfDay()->subDay(), $local->startOfDay()] as $date) {
                foreach (['DAY', 'NIGHT'] as $code) {
                    $definition = $this->definition($definitions, $code, $date);
                    $start = $date->setTimeFromTimeString($definition->start_time);
                    // A transition night meets the next day's boundary without gaps.
                    $end = $code === 'DAY'
                        ? $date->setTimeFromTimeString($definition->end_time)
                        : $date->addDay()->setTimeFromTimeString($this->definition($definitions, 'DAY', $date->addDay())->start_time);
                    if ($local->greaterThanOrEqualTo($start) && $local->lessThan($end)) {
                        return OperationalShift::firstOrCreate(
                            ['operational_date' => $date->toDateString(), 'code' => $code],
                            ['shift_definition_id' => $definition->id, 'name' => $definition->name, 'timezone' => ShiftDefinition::TIMEZONE, 'starts_at' => $start->utc(), 'ends_at' => $end->utc()],
                        );
                    }
                }
            }

            throw new LogicException('Tidak ada definisi shift yang mencakup waktu posting.');
        }, 3);
    }

    private function definition(Collection $definitions, string $code, CarbonImmutable $date): ShiftDefinition
    {
        return $definitions->last(fn (ShiftDefinition $definition) => $definition->code === $code && $definition->effective_from->toDateString() <= $date->toDateString())
            ?? throw new LogicException('Definisi shift belum tersedia. Jalankan MasterDataSeeder.');
    }
}
