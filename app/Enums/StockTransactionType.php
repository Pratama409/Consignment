<?php

namespace App\Enums;

enum StockTransactionType: string
{
    case Opening = 'OPENING';
    case RefillReceipt = 'REFILL_RECEIPT';
    case ManualReceipt = 'MANUAL_RECEIPT';
    case MaterialIssue = 'MATERIAL_ISSUE';
    case Adjustment = 'ADJUSTMENT';
    case Reversal = 'REVERSAL';
}
