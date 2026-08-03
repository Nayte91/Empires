<?php

declare(strict_types=1);

namespace App\Rules\Ruleset;

final readonly class TradeCardStack
{
    /** @param list<TradeCard> $cards */
    public function __construct(
        public int $number,
        public array $cards,
    ) {}
}
