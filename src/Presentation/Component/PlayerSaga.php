<?php

declare(strict_types=1);

namespace App\Presentation\Component;

use App\Rules\Action\Stat;
use App\Rules\Ruleset\Advance;
use App\Rules\Ruleset\AdvanceRegistry;
use App\Rules\ScoreCalculator;
use App\State\Player;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

/**
 * The player's counterpart to GameChronicle: a finished game's read-only retrospective,
 * one player at a time. Static — a finished game never Mercure-refreshes.
 *
 * Everything the live board offers to change the game with is dropped: none of it has anything left
 * to act on, and a control that answers nothing is worse than an absent one. The rename trigger
 * goes with them — a name cannot be changed once the game that earned it is over.
 */
#[AsTwigComponent(template: 'organisms/PlayerSaga.html.twig')]
final class PlayerSaga
{
    /** Same stats ControlBoard offers live, minus astPosition — the shared A.S.T. board already carries that one. */
    private const array COUNTER_STATS = [Stat::Cities, Stat::Ships, Stat::Census, Stat::Treasury, Stat::Cards];

    public Player $player; // @phpstan-ignore property.uninitialized (hydrated by TwigComponent via reflection before use)

    public function __construct(
        private readonly AdvanceRegistry $advanceRegistry,
        private readonly ScoreCalculator $scoreCalculator,
    ) {}

    /** @return list<Advance> */
    public function getOwnedAdvances(): array
    {
        return array_values($this->advanceRegistry->getAdvancesByNames($this->player->advances));
    }

    /** What the owned advances alone are worth, for the Advances heading. */
    public function getAdvancePoints(): int
    {
        return $this->scoreCalculator->advancePointsFor($this->getOwnedAdvances());
    }

    /** @return list<array{key: string, label: string, value: string}> */
    public function getCounters(): array
    {
        return array_map(
            fn (Stat $stat): array => [
                'key' => $stat->value,
                'label' => $stat->label(),
                'value' => $stat->format($stat->read($this->player)),
            ],
            self::COUNTER_STATS,
        );
    }
}
