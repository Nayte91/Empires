<?php

declare(strict_types=1);

namespace App\Rules\Ruleset;

use App\State\Region;

final readonly class Scenario
{
    /**
     * @param list<Region>       $blocks
     * @param list<string>       $empires         in the ruleset's own order, which the empire pickers follow
     * @param array<string, int> $startingCredits
     */
    public function __construct(
        public int $playerCount,
        public array $blocks,
        public array $empires,
        public array $startingCredits,
    ) {}

    public function soleBlock(): ?Region
    {
        return 1 === \count($this->blocks) ? $this->blocks[0] : null;
    }
}
