<?php

namespace App\Data;

use App\Enums\StockDirection;
use InvalidArgumentException;

final readonly class StockPostingLineData
{
    // Four decimal places remain recoverable through SQLite NUMERIC and PHP's float-to-string boundary.
    public const MAX_QUANTITY = '9999999999.9999';

    public string $quantity;

    public function __construct(
        public string $itemId,
        public ?string $vendorItemId,
        public string $locationId,
        public StockDirection $direction,
        string $quantity,
    ) {
        if (! preg_match('/^(0|[1-9][0-9]{0,9})(?:\.[0-9]{1,4})?$/D', $quantity) || ! preg_match('/[1-9]/', $quantity)) {
            throw new InvalidArgumentException('Quantity must be between 0.0001 and '.self::MAX_QUANTITY.' with at most four decimal places.');
        }

        [$whole, $fraction] = array_pad(explode('.', $quantity, 2), 2, '');
        $this->quantity = $whole.'.'.str_pad($fraction, 4, '0');
    }
}
