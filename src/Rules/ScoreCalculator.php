<?php

declare(strict_types=1);

namespace App\Rules;

use App\Rules\Ruleset\Advance;
use App\State\Player;

final class ScoreCalculator
{
    public const int POINTS_PER_AST_POSITION = 5;

    // REFACTOR-WHEN: a 4th VP source lands (monuments, special buildings, per-era AST table
    // from the real game) — switch to composed score rules (tagged iterator) instead of
    // growing this method.

    /** @param list<Advance> $ownedAdvances */
    public function scoreFor(Player $player, array $ownedAdvances): int
    {
        // House rule: 1 VP per city (deliberately not the ÷2 formula in sources/rules/07-victory-conditions/scoring-system.md).
        return $this->advancePointsFor($ownedAdvances)
            + $player->cities
            + $player->astPosition * self::POINTS_PER_AST_POSITION;
    }

    /**
     * The advances term on its own, so the player board can quote it without restating the sum.
     * One definition, so what the heading shows can never drift from what the score counts.
     *
     * @param list<Advance> $ownedAdvances
     */
    public function advancePointsFor(array $ownedAdvances): int
    {
        return array_sum(array_map(
            static fn (Advance $advance): int => $advance->points,
            $ownedAdvances
        ));
    }
}
