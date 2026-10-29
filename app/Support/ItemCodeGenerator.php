<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

class ItemCodeGenerator
{
    public function next(): string
    {
        $sequence = DB::table('number_sequences')->where('name', 'item_code')->lockForUpdate()->firstOrFail();
        $number = $sequence->last_value + 1;
        DB::table('number_sequences')->where('id', $sequence->id)->update(['last_value' => $number, 'updated_at' => now()]);

        return 'ITM-'.str_pad((string) $number, 6, '0', STR_PAD_LEFT);
    }
}
