<?php

namespace App\Enums;

enum OpeningBalanceBatchStatus: string
{
    case Preview = 'PREVIEW';
    case Activated = 'ACTIVATED';
}
