<?php

namespace App\Enums;

enum StockOpnameScope: string
{
    case Full = 'FULL';
    case Partial = 'PARTIAL';
    case Cycle = 'CYCLE';
}
