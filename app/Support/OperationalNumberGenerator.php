<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

class OperationalNumberGenerator
{
    public function next(string $name): int
    {
        $sequence = DB::table('number_sequences')->where('name', $name)->lockForUpdate()->firstOrFail();
        $next = $sequence->last_value + 1;
        DB::table('number_sequences')->where('id', $sequence->id)->update(['last_value' => $next, 'updated_at' => now()]);

        return $next;
    }
}
