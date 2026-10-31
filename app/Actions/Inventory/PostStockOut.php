<?php

namespace App\Actions\Inventory;

use App\Data\StockPostingData;
use App\Enums\StockDirection;
use App\Models\StockTransaction;

class PostStockOut
{
    public function __construct(private PostStock $posting) {}

    public function handle(StockPostingData $data): StockTransaction
    {
        return $this->posting->handle($data, StockDirection::Out);
    }
}
