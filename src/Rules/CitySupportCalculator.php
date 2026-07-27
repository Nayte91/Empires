<?php

declare(strict_types=1);

namespace App\Rules;

use App\State\Player;

/**
 * The game's single authority on city support: every city needs two population to stand. Both the
 * warning a player gets and the margin the outlook board shows read this object, so the threshold
 * exists once.
 */
final readonly class CitySupportCalculator
{
    /** Public because founding a city has to reserve this floor before it is paid for. */
    public const int CENSUS_PER_CITY = 2;

    /** Population the player's cities demand. */
    public function required(Player $player): int
    {
        return self::CENSUS_PER_CITY * $player->cities;
    }

    /** Population the player can still lose before the cities stop being supported. */
    public function spareCensus(Player $player): int
    {
        return max(0, $player->census - $this->required($player));
    }

    /** The one question consumers should ask. */
    public function citiesAreUnsupported(Player $player): bool
    {
        return $player->census < $this->required($player);
    }
}
