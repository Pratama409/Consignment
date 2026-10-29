<?php

namespace App\Data;

use App\Enums\StockTransactionType;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

final readonly class StockPostingData
{
    /** @param list<StockPostingLineData> $lines */
    public function __construct(
        public StockTransactionType $type,
        public string $sourceType,
        public string $sourceId,
        public CarbonImmutable $physicalOccurredAt,
        public string $actorId,
        public string $idempotencyKey,
        public ?string $reason,
        public array $lines,
    ) {
        if ($lines === [] || ! array_is_list($lines)) {
            throw new InvalidArgumentException('Posting requires a nonempty list of stock posting lines.');
        }

        foreach ($lines as $line) {
            if (! $line instanceof StockPostingLineData) {
                throw new InvalidArgumentException('Posting requires stock posting line DTOs.');
            }
        }
    }
}
