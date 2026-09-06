<?php

declare(strict_types=1);

namespace App\Rules;

use App\Rules\Shop\ShopConnector;
use App\State\Game;
use App\State\Player;
use App\State\Repository\OrderRepositoryInterface;

final readonly class PurchaseHistoryCalculator
{
    public const int AVERAGE_FROM_TURN = ShopConnector::OPENING_TURN;

    public function __construct(private OrderRepositoryInterface $orderRepository) {}

    /** @return array<int, list<string>> */
    public function keysPerTurn(Player $player): array
    {
        $keysByTurn = [];

        foreach ($this->orderRepository->findValidatedByPlayer($player) as $order) {
            $keysByTurn[$order->turn] = [...($keysByTurn[$order->turn] ?? []), ...$order->keys()];
        }

        ksort($keysByTurn);

        return $keysByTurn;
    }

    /** @return list<int> */
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

    public function averageFromTurnSix(Player $player): ?float
    {
        $currentTurn = $player->game->currentTurn;

        if ($currentTurn < self::AVERAGE_FROM_TURN) {
            return null;
        }

        $consideredTotals = \array_slice($this->totalsPerTurn($player), self::AVERAGE_FROM_TURN - 1);

        return array_sum($consideredTotals) / \count($consideredTotals);
    }

    public function tableAverageFromTurnSix(Game $game): ?float
    {
        $averages = [];

        foreach ($game->players as $player) {
            $average = $this->averageFromTurnSix($player);

            if (null === $average) {
                return null;
            }

            $averages[] = $average;
        }

        return [] === $averages ? null : array_sum($averages) / \count($averages);
    }
}
