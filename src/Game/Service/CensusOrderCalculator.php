<?php

declare(strict_types=1);

namespace App\Game\Service;

use App\Entity\GameSession;
use App\Entity\Player;
use App\Game\EmpireCatalog;

/**
 * Computes the movement-phase play order: highest census first, Military-advance
 * owners last, ties broken by empire position on the A.S.T. ranking track.
 */
final readonly class CensusOrderCalculator
{
    public const string MILITARY_ADVANCE = 'military';

    public function __construct(private EmpireCatalog $empireCatalog) {}

    /** @return list<Player> */
    public function orderFor(GameSession $game): array
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
        return in_array(self::MILITARY_ADVANCE, $player->advances, true);
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
