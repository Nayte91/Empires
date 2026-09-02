<?php

declare(strict_types=1);

namespace App\Presentation\Component;

use App\Rules\Ruleset\Advance;
use App\Rules\Ruleset\AdvanceRegistry;
use App\Rules\ScoreCalculator;
use App\State\Player;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\DefaultActionTrait;

/**
 * Live for one reason: a Mercure ping asks it to re-render itself. It carries no write of its own —
 * the rename moved out to {@see PlayerHeading}, the stat pickers are components in their own right.
 */
#[AsLiveComponent(template: 'organisms/PlayerBoard.html.twig')]
final class PlayerBoard
{
    use DefaultActionTrait;

    #[LiveProp]
    public Player $player; // @phpstan-ignore property.uninitialized (hydrated by LiveComponent via reflection before use)

    public function __construct(
        private readonly AdvanceRegistry $advanceRegistry,
        private readonly ScoreCalculator $scoreCalculator,
    ) {}

    /** @return list<Advance> */
    public function getOwnedAdvances(): array
    {
        return array_values($this->advanceRegistry->getAdvancesByNames($this->player->advances));
    }

    /**
     * What the owned advances alone are worth, for the Advances heading — quoted from the score
     * itself so the two can never drift. Zero is printed rather than hidden: a heading that keeps
     * its shape is easier to read turn over turn, and a zero in a named source is information.
     */
    public function getAdvancePoints(): int
    {
        return $this->scoreCalculator->advancePointsFor($this->getOwnedAdvances());
    }
}
