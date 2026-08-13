<?php

declare(strict_types=1);

namespace App\Rules;

use App\Rules\Ruleset\Advance;
use App\Rules\Ruleset\AdvanceRegistry;
use App\Rules\Ruleset\AstRegistry;
use App\State\ASTVersion;
use App\State\Player;

final readonly class AstProgressionCalculator
{
    public function __construct(
        private AstRegistry $astRegistry,
        private AdvanceRegistry $advanceRegistry,
    ) {}

    /**
     * @param list<list<string>> $ownedPerTurn the advance keys held at the end of each turn, turn 1 first
     *
     * @return list<int> the marker's position at the end of each turn, same length
     */
    public function positionsPerTurn(Player $player, array $ownedPerTurn): array
    {
        $version = $player->game->astVersion;
        $group = $this->astRegistry->resolveEmpireGroup($version, $player->empire);
        $ceiling = min($player->astPosition, $this->astRegistry->getTrackLength($version, $group) - 1);

        $position = 0;
        $positions = [];

        foreach ($ownedPerTurn as $owned) {
            if ($position < $ceiling && $this->mayEnter($position + 1, $owned, $version, $group)) {
                ++$position;
            }

            $positions[] = $position;
        }

        if ([] !== $positions) {
            $positions[array_key_last($positions)] = $ceiling;
        }

        return $positions;
    }

    /** @param list<string> $ownedKeys */
    private function mayEnter(int $position, array $ownedKeys, ASTVersion $version, string $group): bool
    {
        $era = $this->astRegistry->getEraForPosition($position, $version, $group);
        $requirements = ASTVersion::EXPERT === $version ? $era->expertRequirements : $era->basicRequirements;

        $owned = array_values($this->advanceRegistry->getAdvancesByNames($ownedKeys));

        $countable = match (true) {
            isset($requirements['min_advance_cost']) => $this->countAdvancesCostingAtLeast($owned, $requirements['min_advance_cost']),
            isset($requirements['max_advance_cost']) => $this->countAdvancesCostingAtMost($owned, $requirements['max_advance_cost']),
            default => \count($owned),
        };

        return $countable >= ($requirements['advances'] ?? 0)
            && $this->sumVictoryPoints($owned) >= ($requirements['advance_points'] ?? 0);
    }

    /** @param list<Advance> $owned */
    private function countAdvancesCostingAtLeast(array $owned, int $min): int
    {
        return \count(array_filter($owned, static fn (Advance $advance): bool => $advance->cost >= $min));
    }

    /** @param list<Advance> $owned */
    private function countAdvancesCostingAtMost(array $owned, int $max): int
    {
        return \count(array_filter($owned, static fn (Advance $advance): bool => $advance->cost <= $max));
    }

    /** @param list<Advance> $owned */
    private function sumVictoryPoints(array $owned): int
    {
        return array_sum(array_map(static fn (Advance $advance): int => $advance->points, $owned));
    }
}
