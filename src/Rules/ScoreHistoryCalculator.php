<?php

declare(strict_types=1);

namespace App\Rules;

use App\Rules\Ruleset\Advance;
use App\Rules\Ruleset\AdvanceRegistry;
use App\State\Game;
use App\State\Repository\OrderRepositoryInterface;

/**
 * What each empire was worth, turn by turn, read back off the shop's own record. A score is the
 * points of the advances owned plus the marker's position on the A.S.T., five points a square.
 *
 * Nothing snapshots a past turn — cities and A.S.T. position are counters the operator overwrites.
 * Orders are the exception, one validated basket per player and per turn, and the shop sells
 * nothing but advances: the hand held at the end of turn T is the union of the baskets up to T.
 *
 * A reconstructed point is therefore short of {@see ScoreCalculator} by its cities, which have no
 * history and no rule to replay them from. The last turn escapes that: it is not reconstructed but
 * taken whole from {@see StandingsCalculator}, so the curve lands on the score the A.S.T. board
 * prints. The step this creates on the final turn is the price of the two agreeing.
 */
final readonly class ScoreHistoryCalculator
{
    public function __construct(
        private OrderRepositoryInterface $orderRepository,
        private AdvanceRegistry $advanceRegistry,
        private AstProgressionCalculator $astProgressionCalculator,
        private StandingsCalculator $standingsCalculator,
    ) {}

    /**
     * One series per player, indexed by slug, from turn 1 to the turn the game stopped on. A player
     * who never bought anything still gets one — a flat line is an answer.
     *
     * @return array<string, list<int>>
     */
    public function pointsPerTurn(Game $game): array
    {
        $baskets = $this->basketsPerTurn($game);

        $series = [];

        foreach ($game->players as $player) {
            $ownedPerTurn = $this->ownedPerTurn($game, $baskets[$player->slug] ?? []);
            $positions = $this->astProgressionCalculator->positionsPerTurn($player, $ownedPerTurn);

            $line = array_map(
                fn (array $owned, int $position): int => $this->pointsOf($owned)
                    + $position * ScoreCalculator::POINTS_PER_AST_POSITION,
                $ownedPerTurn,
                $positions,
            );

            if ([] !== $line) {
                $line[array_key_last($line)] = $this->standingsCalculator->scoreOf($player);
            }

            $series[$player->slug] = $line;
        }

        return $series;
    }

    /**
     * @param array<int, list<string>> $baskets
     *
     * @return list<list<string>>
     */
    private function ownedPerTurn(Game $game, array $baskets): array
    {
        $owned = [];
        $ownedPerTurn = [];

        for ($turn = 1; $turn <= $game->currentTurn; ++$turn) {
            $owned = [...$owned, ...($baskets[$turn] ?? [])];
            $ownedPerTurn[] = $owned;
        }

        return $ownedPerTurn;
    }

    /** @return array<string, array<int, list<string>>> */
    private function basketsPerTurn(Game $game): array
    {
        $baskets = [];

        foreach ($this->orderRepository->findValidatedByGame($game) as $order) {
            $baskets[$order->player->slug][$order->turn] = $order->keys();
        }

        return $baskets;
    }

    /** @param list<string> $keys */
    private function pointsOf(array $keys): int
    {
        return array_sum(array_map(
            static fn (Advance $advance): int => $advance->points,
            $this->advanceRegistry->getAdvancesByNames($keys),
        ));
    }
}
