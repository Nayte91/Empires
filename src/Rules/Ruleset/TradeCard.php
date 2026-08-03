<?php

declare(strict_types=1);

namespace App\Rules\Ruleset;

final readonly class TradeCard
{
    /** @param list<null|int> $quantities aligned with TradeCardTable::$columns; null means the card is absent from that column, not present in quantity zero */
    public function __construct(
        public string $name,
        public string $type,
        public string $game,
        public array $quantities,
    ) {}
}
