<?php

declare(strict_types=1);

namespace App\Rules;

use App\Infrastructure\Repository\OrderRepository;
use App\State\Player;

/**
 * What a player spent, turn by turn, read back off their own validated orders — the shop-side
 * counterpart to {@see ScoreHistoryCalculator}, but per player and narrower: how much buying
 * cost, not what it was worth.
 */
final readonly class PurchaseHistoryCalculator
{
    /**
     * The average only starts once the shop has been open a few turns: `bretonneux`, the
     * project's one real finished game, made no purchase before turn 6.
     */
    public const int AVERAGE_FROM_TURN = 6;

    public function __construct(private OrderRepository $orderRepository) {}

    /**
     * One total per turn, turn 1 to the turn the game stopped on. A turn with no validated
     * order counts as zero rather than being skipped — a gap in buying is still an answer.
     *
     * @return list<int>
     */
    public function totalsPerTurn(Player $player): array
    {
        $totalsByTurn = [];

        foreach ($this->orderRepository->findValidatedByPlayer($player) as $order) {
            $totalsByTurn[$order->turn] = $order->total ?? 0;
        }

        $totals = [];

        for ($turn = 1; $turn <= $player->game->currentTurn; ++$turn) {
            $totals[] = $totalsByTurn[$turn] ?? 0;
        }

        return $totals;
    }

    /**
     * Average spend per turn of play from {@see AVERAGE_FROM_TURN} onward — divided by every
     * turn elapsed since then, not only the turns a purchase happened, so it answers "how much
     * per turn of play" rather than "how much per turn bought". Null when the game never
     * reached that turn: there is nothing to average, and a zero would read as a measurement.
     */
    public function averageFromTurnSix(Player $player): ?float
    {
        $currentTurn = $player->game->currentTurn;

        if ($currentTurn < self::AVERAGE_FROM_TURN) {
            return null;
        }

        $consideredTotals = \array_slice($this->totalsPerTurn($player), self::AVERAGE_FROM_TURN - 1);

        return array_sum($consideredTotals) / \count($consideredTotals);
    }
}
