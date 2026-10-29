<?php

namespace App\Events;

use App\Models\StockTransaction;

class StockTransactionReversed
{
    public function __construct(public StockTransaction $original, public StockTransaction $reversal) {}
}
