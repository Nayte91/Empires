<?php

declare(strict_types=1);

namespace App\Presentation\Shop;

/**
 * The orders the operator's order list can be shown in. An enum rather than a free string, for the
 * same reason as CatalogSort: a selector renders its cases, and an unrecognised value cannot reach
 * the sort at all.
 *
 * It sorts built cards rather than being a SQL ORDER BY because most cards have no order behind
 * them: every turn of every player gets a card, and the queue's whole point is to lift the
 * current turn's *missing* orders — rows that exist nowhere in the database — to the top.
 *
 * @phpstan-import-type OrderCard from OrderCardProvider
 */
enum OrderCardSort: string
{
    /** What the operator must act on first: the current turn's queue, then the archive. */
    case Urgency = 'urgency';

    /**
     * @param OrderCard $a
     * @param OrderCard $b
     */
    public function compare(array $a, array $b): int
    {
        $currentTurn = $a['player']->game->currentTurn;

        return $this->rankOf($a, $currentTurn) <=> $this->rankOf($b, $currentTurn);
    }

    /**
     * @param OrderCard $card
     *
     * @return array{int, int, int, int}
     */
    private function rankOf(array $card, int $currentTurn): array
    {
        $isCurrent = $card['turn'] === $currentTurn;

        $bucket = match (true) {
            $isCurrent && 'pending' === $card['status'] => 0,
            $isCurrent && 'missing' === $card['status'] => 1,
            $isCurrent => 2,
            'empty' !== $card['status'] => 3,
            default => 4,
        };

        return [$bucket, -$card['turn'], 'validated' === $card['status'] ? 1 : 0, $card['seat']];
    }
}
