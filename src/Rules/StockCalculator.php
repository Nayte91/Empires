<?php

declare(strict_types=1);

namespace App\Rules;

use App\Rules\Action\Stat;
use App\Rules\Ruleset\GameData;
use App\State\Player;

/**
 * The game's single authority on the shared token stock. Population and treasury are drawn from
 * one finite pile, so neither can be read without the other: raising one lowers the ceiling of its
 * twin, and what is left on the table is what pays the taxes.
 */
final readonly class StockCalculator
{
    public function __construct(private GameData $gameData) {}

    /** Tokens in play, all holders together. */
    public function pool(): int
    {
        return $this->gameData->getLimits()['max_population'] ?? 0;
    }

    /** Tokens no player holder has claimed, and so still on the table. */
    public function available(Player $player): int
    {
        return $this->pool() - $player->census - $player->treasury;
    }

    /** Whether a stat is one of the two holders drawing from the pile. */
    public function drawsFromStock(Stat $stat): bool
    {
        return Stat::Census === $stat || Stat::Treasury === $stat;
    }

    /**
     * The highest a stat can reach right now. For a stock holder its twin has already claimed part
     * of the pile; any other stat answers to its own bound alone.
     */
    public function ceilingFor(Player $player, Stat $stat): int
    {
        return match ($stat) {
            Stat::Census => $this->pool() - $player->treasury,
            Stat::Treasury => $this->pool() - $player->census,
            default => $stat->max(),
        };
    }
}
