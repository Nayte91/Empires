<?php

declare(strict_types=1);

namespace App\Rules;

use App\Rules\Ruleset\AdvanceEffect;
use App\Rules\Ruleset\AdvanceEffectRegistry;
use App\Rules\Ruleset\GameRegistry;
use App\State\Player;

/**
 * The game's single authority on founding cities.
 *
 * A city costs population twice over: the tokens spent to raise it, and the two it permanently
 * adds to the support floor. Counting only the first would advise a build that trips the
 * city-support alarm the moment it is followed — so the floor is reserved up front.
 */
final readonly class CityBuildCalculator
{
    private const int POPULATION_COST = 6;

    /** The rebate trades coin for population one-for-one, down to half the usual cost. */
    private const int MAX_REBATE = 3;

    public function __construct(
        private CitySupportCalculator $citySupportCalculator,
        private GameRegistry $gameRegistry,
        private AdvanceEffectRegistry $advanceEffects,
    ) {}

    /** How many cities the player could found right now, everything considered. */
    public function affordableCities(Player $player): int
    {
        $budget = $player->census - $this->citySupportCalculator->required($player) + $this->buildRebate($player);

        return min(
            intdiv(max(0, $budget), self::POPULATION_COST + CitySupportCalculator::CENSUS_PER_CITY),
            $this->remainingCitySlots($player),
        );
    }

    public function remainingCitySlots(Player $player): int
    {
        return max(0, ($this->gameRegistry->getLimits()['max_cities'] ?? 0) - $player->cities);
    }

    /**
     * The rebate discounts one city per turn, and only as far as the treasury can pay: three coins
     * buy it in full, one coin buys a third of it.
     */
    private function buildRebate(Player $player): int
    {
        if (!$this->advanceEffects->grants($player->advances, AdvanceEffect::CityBuildRebate)) {
            return 0;
        }

        return min($player->treasury, self::MAX_REBATE);
    }
}
