<?php

declare(strict_types=1);

namespace App\Rules;

use App\Rules\Ruleset\Advance;
use App\Rules\Ruleset\AdvanceRegistry;
use App\Rules\Ruleset\AstRegistry;
use App\State\ASTVersion;
use App\State\Player;

/**
 * Where an empire's marker stood at the end of each turn, replayed rather than remembered.
 *
 * Nothing records the marker's past: `Player::$astPosition` is a counter the operator overwrites.
 * But the board's own gates are checks on things the purchase history does answer — how many
 * advances, of what cost, worth how many points — so the run can be replayed from the advances a
 * player held at each turn, one square per turn, gated by the era the next square belongs to.
 *
 * Two deliberate imprecisions, both of which make the replay optimistic:
 *  - the `cities` requirement is skipped, having no history to read;
 *  - a calamity that pushed a marker backwards leaves no trace to replay.
 * Hence the ceiling: the replayed marker is never allowed past where the player actually finished,
 * and the last turn is not replayed at all but read off the player. The deduction fills the middle
 * of the curve; both of its ends are facts.
 */
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

    /**
     * Whether the era the square belongs to would let this hand of advances in. `cities` is read
     * and ignored on purpose — see the class docblock.
     *
     * @param list<string> $ownedKeys
     */
    private function mayEnter(int $position, array $ownedKeys, ASTVersion $version, string $group): bool
    {
        $era = $this->astRegistry->getEraForPosition($position, $version, $group);
        $requirements = ASTVersion::EXPERT === $version ? $era->expertRequirements : $era->basicRequirements;

        $advances = array_values($this->advanceRegistry->getAdvancesByNames($ownedKeys));

        // The cost bounds are not a separate condition: they say which advances the count is
        // allowed to consider — "three advances costing 100 or more", not "three advances, one of
        // which costs 100 or more".
        $countable = array_filter(
            $advances,
            static fn (Advance $advance): bool => $advance->cost >= ($requirements['min_advance_cost'] ?? 0)
                && $advance->cost <= ($requirements['max_advance_cost'] ?? PHP_INT_MAX),
        );

        if (\count($countable) < ($requirements['advances'] ?? 0)) {
            return false;
        }

        $points = array_sum(array_map(static fn (Advance $advance): int => $advance->points, $advances));

        return $points >= ($requirements['advance_points'] ?? 0);
    }
}
