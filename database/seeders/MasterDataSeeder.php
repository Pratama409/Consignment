<?php

namespace Database\Seeders;

use App\Models\Location;
use App\Models\ShiftDefinition;
use App\Models\Unit;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class MasterDataSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function (): void {
            foreach (['DAY' => ['Siang', '06:00', '18:00'], 'NIGHT' => ['Malam', '18:00', '06:00']] as $code => [$name, $start, $end]) {
                if (! ShiftDefinition::where('code', $code)->exists()) {
                    ShiftDefinition::firstOrCreate(['code' => $code, 'effective_from' => '1970-01-01'], ['name' => $name, 'start_time' => $start, 'end_time' => $end]);
                }
            }

            foreach (['PC' => 'Piece', 'SET' => 'Set', 'KG' => 'Kilogram', 'M' => 'Meter', 'L' => 'Liter'] as $code => $name) {
                Unit::firstOrCreate(['code' => $code], ['name' => $name, 'is_active' => true]);
            }

            Location::firstOrCreate(
                ['location_code' => Location::UNASSIGNED],
                ['location_name' => 'Lokasi belum ditentukan', 'is_active' => true],
            );
        });
    }
}
