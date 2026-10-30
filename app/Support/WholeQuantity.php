<?php

namespace App\Support;

final class WholeQuantity
{
    public static function isPositive(string|int|float|null $quantity): bool
    {
        return preg_match('/^[1-9][0-9]*(?:\.0+)?$/', (string) $quantity) === 1;
    }
}
