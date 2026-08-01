<?php

declare(strict_types=1);

namespace App\Rules;

use App\Rules\Action\Stat;
use App\Rules\Ruleset\AstRegistry;
use App\Rules\Ruleset\GameRegistry;
use App\State\Player;

/**
 * The game's single authority on how far a stat may travel. It composes the authorities that
 * already own each bound rather than replacing them: GameRegistry for the table's own limits,
 * StockCalculator for the census/treasury shared pool, AstRegistry for the exact track length of
 * the player's own AST version and empire group.
 */
final readonly class StatBoundsCalculator
{
    public function __construct(
        private GameRegistry $gameRegistry,
        private StockCalculator $stockCalculator,
        private AstRegistry $astRegistry,
    ) {}

    /**
     * Census floors at two, not one: a player down to a single population is out of the game, so
     * offering that value in the picker serves nobody. Everything else starts at zero.
     */
    public function floorFor(Player $player, Stat $stat): int
    {
        return match ($stat) {
            Stat::Census => 2,
            default => 0,
        };
    }

    /**
     * The highest value the stat could ever reach in this game, ignoring what the other holders
     * currently claim. Distinct from {@see ceilingFor()} on purpose: the picker grid offers this
     * whole range and greys out what is momentarily out of reach, so a player can see that a
     * value exists and is merely unavailable — rather than watch tiles vanish as their twin grows.
     */
    public function displayCeilingFor(Player $player, Stat $stat): int
    {
        return match ($stat) {
            // The pool minus whatever its twin can never give up, so the top tile is the one
            // reachable when the twin sits at its own floor.
            Stat::Census => $this->stockCalculator->pool() - $this->floorFor($player, Stat::Treasury),
            Stat::Treasury => $this->stockCalculator->pool() - $this->floorFor($player, Stat::Census),
            default => $this->ceilingFor($player, $stat),
        };
    }

    public function ceilingFor(Player $player, Stat $stat): int
    {
        return match ($stat) {
            Stat::Cities => $this->gameRegistry->getLimits()['max_cities'] ?? 0,
            Stat::Census, Stat::Treasury => $this->stockCalculator->ceilingFor($player, $stat),
            Stat::Ships => $this->gameRegistry->getLimits()['max_ships'] ?? 0,
            Stat::Cards => $this->gameRegistry->getLimits()['max_cards'] ?? 0,
            Stat::AstPosition => $this->astPositionCeiling($player),
        };
    }

    private function astPositionCeiling(Player $player): int
    {
        $group = $this->astRegistry->resolveEmpireGroup($player->game->astVersion, $player->empire);

        return $this->astRegistry->getTrackLength($player->game->astVersion, $group) - 1;
    }
}
