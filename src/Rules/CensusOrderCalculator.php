<?php

declare(strict_types=1);

namespace App\Rules;

use App\Rules\Ruleset\AdvanceEffect;
use App\Rules\Ruleset\AdvanceEffectCatalog;
use App\Rules\Ruleset\EmpireCatalog;
use App\State\Game;
use App\State\Player;

/**
 * Computes the movement-phase play order: highest census first, Military-advance
 * owners last, ties broken by empire position on the A.S.T. ranking track.
 */
final readonly class CensusOrderCalculator
{
    public function __construct(
        private EmpireCatalog $empireCatalog,
        private AdvanceEffectCatalog $advanceEffects,
    ) {}

    /** @return list<Player> */
    public function orderFor(Game $game): array
    {
        $players = $game->players->toArray();
        usort($players, fn (Player $a, Player $b): int => $this->sortKey($a) <=> $this->sortKey($b));

        return $players;
    }

    /** Where a single player sits in that order, counting from 1. */
    public function rankOf(Player $player): int
    {
        $rank = array_search($player, $this->orderFor($player->game), true);

        return \is_int($rank) ? $rank + 1 : 1;
    }

    public function hasMilitary(Player $player): bool
    {
        return null !== $this->militaryAdvanceOf($player);
    }

    /**
     * The advance that sends its owner to the back of the order, when they own one — its key, so a
     * consumer can say which advance explains the rank without naming it itself.
     */
    public function militaryAdvanceOf(Player $player): ?string
    {
        return $this->advanceEffects->owned($player->advances, AdvanceEffect::MovesLast)[0] ?? null;
    }

    /** @return array{bool, int, int} */
    private function sortKey(Player $player): array
    {
        return [$this->hasMilitary($player), -$player->census, $this->empirePosition($player)];
    }

    private function empirePosition(Player $player): int
    {
        return $this->empireCatalog->positionOf($player->empire);
    }
}
