<?php

declare(strict_types=1);

namespace App\Game\Service;

use App\Entity\Advance;
use App\Entity\Player;

final class ScoreCalculator
{
    public const int POINTS_PER_AST_POSITION = 5;

    // REFACTOR-WHEN: a 4th VP source lands (monuments, special buildings, per-era AST table
    // from the real game) — switch to composed score rules (tagged iterator) instead of
    // growing this method.

    /** @param list<Advance> $ownedAdvances */
    public function scoreFor(Player $player, array $ownedAdvances): int
    {
        $advancePoints = array_sum(array_map(
            static fn (Advance $advance): int => $advance->points,
            $ownedAdvances
        ));

        // House rule: 1 VP per city (deliberately not the ÷2 formula in sources/rules/07-victory-conditions/scoring-system.md).
        return $advancePoints + $player->cities + $player->astPosition * self::POINTS_PER_AST_POSITION;
    }
}
