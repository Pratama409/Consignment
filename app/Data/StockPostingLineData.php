<?php

namespace App\Data;

use App\Enums\StockDirection;
use InvalidArgumentException;

final readonly class StockPostingLineData
{
    public string $quantity;

    public function __construct(
        public string $itemId,
        public ?string $vendorItemId,
        public string $locationId,
        public StockDirection $direction,
        string $quantity,
    ) {
        if (! preg_match('/^(0|[1-9][0-9]{0,13})(?:\.[0-9]{1,4})?$/D', $quantity) || ! preg_match('/[1-9]/', $quantity)) {
            throw new InvalidArgumentException('Quantity must be positive with at most fourteen integer and four decimal digits.');
        }

        [$whole, $fraction] = array_pad(explode('.', $quantity, 2), 2, '');
        $this->quantity = $whole.'.'.str_pad($fraction, 4, '0');
    }
}
