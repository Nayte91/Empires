<?php

declare(strict_types=1);

namespace App\Game\Scenario;

use App\Game\Advisory\HandLimitRule;
use App\Game\Command\CreateGame;

final readonly class HandLimitSummaryRule implements ScenarioRule
{
    public function __construct(
        private HandLimitRule $handLimitRule,
    ) {}

    public function describe(CreateGame $game): string
    {
        return sprintf('Card limit: %d', $this->handLimitRule->limitFor($game->playerCount));
    }
}
