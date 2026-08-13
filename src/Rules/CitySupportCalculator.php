<?php

declare(strict_types=1);

namespace App\Rules;

use App\State\Player;

final readonly class CitySupportCalculator
{
    public const int CENSUS_PER_CITY = 2;

    public function required(Player $player): int
    {
        return self::CENSUS_PER_CITY * $player->cities;
    }

    public function citiesAreUnsupported(Player $player): bool
    {
        return $player->census < $this->required($player);
    }
}
