<?php

declare(strict_types=1);

namespace App\Game\Scenario;

use App\Game\Command\CreateGame;
use App\Game\Service\HandSizeCalculator;

final readonly class HandLimitSummaryRule implements ScenarioRule
{
    public function __construct(private HandSizeCalculator $handSizeCalculator) {}

    /** The base limit, deliberately: at creation nobody owns an advance that could raise it yet. */
    public function describe(CreateGame $game): string
    {
        return sprintf('Card limit: %d', $this->handSizeCalculator->baseLimitFor($game->playerCount));
    }
}
