<?php

declare(strict_types=1);

namespace App\Rules;

use App\Rules\Ruleset\AdvanceEffect;
use App\Rules\Ruleset\AdvanceEffectRegistry;
use App\Rules\Ruleset\EmpireRegistry;
use App\State\Game;
use App\State\Player;

final readonly class CensusOrderCalculator
{
    public function __construct(
        private EmpireRegistry $empireRegistry,
        private AdvanceEffectRegistry $advanceEffects,
    ) {}

    /** @return list<Player> */
    public function orderFor(Game $game): array
    {
        $players = $game->players->toArray();
        usort($players, fn (Player $a, Player $b): int => $this->sortKey($a) <=> $this->sortKey($b));

        return $players;
    }

    public function rankOf(Player $player): int
    {
        $rank = array_search($player, $this->orderFor($player->game), true);

        return \is_int($rank) ? $rank + 1 : 1;
    }

    public function hasMilitary(Player $player): bool
    {
        return null !== $this->militaryAdvanceOf($player);
    }

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
        return $this->empireRegistry->positionOf($player->empire);
    }
}
