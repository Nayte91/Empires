<?php

declare(strict_types=1);

namespace App\Rules;

use App\Rules\Ruleset\AdvanceEffect;
use App\Rules\Ruleset\AdvanceEffectRegistry;
use App\Rules\Ruleset\GameRegistry;
use App\State\Player;

final readonly class CityBuildCalculator
{
    private const int REGULAR_COST = 6;

    public function __construct(
        private CitySupportCalculator $citySupportCalculator,
        private GameRegistry $gameRegistry,
        private AdvanceEffectRegistry $advanceEffects,
    ) {}

    public function affordableCities(Player $player): int
    {
        $budget = $player->census - $this->citySupportCalculator->required($player) + $this->buildRebate($player, self::REGULAR_COST);

        return min(
            intdiv(max(0, $budget), self::REGULAR_COST + CitySupportCalculator::CENSUS_PER_CITY),
            $this->remainingCitySlots($player),
        );
    }

    public function remainingCitySlots(Player $player): int
    {
        return max(0, ($this->gameRegistry->getLimits()['max_cities'] ?? 0) - $player->cities);
    }

    /** Architecture lets the treasury stand in for population, up to half the price of the city being founded */
    private function buildRebate(Player $player, int $cityCost): int
    {
        if (!$this->advanceEffects->grants($player->advances, AdvanceEffect::CityBuildRebate)) {
            return 0;
        }

        return min($player->treasury, intdiv($cityCost, 2));
    }
}
