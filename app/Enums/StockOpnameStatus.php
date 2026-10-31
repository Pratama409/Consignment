<?php

namespace App\Enums;

enum StockOpnameStatus: string
{
    case Counting = 'COUNTING';
    case Counted = 'COUNTED';
    case Resolving = 'RESOLVING';
    case Completed = 'COMPLETED';
    case Cancelled = 'CANCELLED';

    /** An open session can still freeze its buckets. */
    public function isOpen(): bool
    {
        return in_array($this, [self::Counting, self::Counted, self::Resolving], true);
    }
}
