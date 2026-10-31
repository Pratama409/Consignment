<?php

namespace App\Enums;

enum InventoryPeriodStatus: string
{
    case Draft = 'DRAFT';
    case Active = 'ACTIVE';
    case Closed = 'CLOSED';
}
