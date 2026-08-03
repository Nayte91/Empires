<?php

declare(strict_types=1);

namespace App\Rules\Ruleset;

final readonly class TradeCardTable
{
    /**
     * @param list<string>         $columns column headers, in display order; empty when the player count/region combination has no defined distribution
     * @param list<TradeCardStack> $stacks
     */
    public function __construct(
        public array $columns,
        public array $stacks,
    ) {}
}
