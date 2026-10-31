<?php

namespace App\Enums;

enum StockTransactionStatus: string
{
    case Draft = 'DRAFT';
    case Posted = 'POSTED';
}
