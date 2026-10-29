<?php

namespace App\Actions\Inventory;

use App\Data\StockPostingData;
use App\Enums\StockDirection;
use App\Models\InventoryPeriod;
use App\Models\StockTransaction;

class PostStockIn
{
    public function __construct(private PostStock $posting) {}

    public function handle(StockPostingData $data): StockTransaction
    {
        return $this->posting->handle($data, StockDirection::In);
    }

    /** Only ActivateOpeningBalance may call this inside its batch/period transaction. */
    public function handleOpening(StockPostingData $data, InventoryPeriod $lockedPeriod): StockTransaction
    {
        return $this->posting->handle($data, StockDirection::In, $lockedPeriod);
    }
}
