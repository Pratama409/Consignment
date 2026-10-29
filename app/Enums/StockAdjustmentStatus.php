<?php

namespace App\Enums;

enum StockAdjustmentStatus: string
{
    case Draft = 'DRAFT';
    case Approved = 'APPROVED';
    case Rejected = 'REJECTED';
    case Posted = 'POSTED';
}
