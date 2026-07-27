<?php

declare(strict_types=1);

namespace App\Game\Service;

use App\Entity\Player;
use App\Game\GameData;

/**
 * The game's single authority on founding cities.
 *
 * A city costs population twice over: the tokens spent to raise it, and the two it permanently
 * adds to the support floor. Counting only the first would advise a build that trips the
 * city-support alarm the moment it is followed — so the floor is reserved up front.
 */
final readonly class CityBuildCalculator
{
    /** Founding a city one turn cheaper, against coin. */
    public const string ARCHITECTURE_ADVANCE = 'architecture';

    private const int POPULATION_COST = 6;

    /** Architecture trades coin for population one-for-one, down to half the usual cost. */
    private const int MAX_ARCHITECTURE_REBATE = 3;

    public function __construct(
        private CitySupportCalculator $citySupportCalculator,
        private GameData $gameData,
    ) {}

    /** How many cities the player could found right now, everything considered. */
    public function affordableCities(Player $player): int
    {
        $budget = $player->census - $this->citySupportCalculator->required($player) + $this->architectureRebate($player);

        return min(
            intdiv(max(0, $budget), self::POPULATION_COST + CitySupportCalculator::CENSUS_PER_CITY),
            $this->remainingCitySlots($player),
        );
    }

    public function remainingCitySlots(Player $player): int
    {
        return max(0, ($this->gameData->getLimits()['max_cities'] ?? 0) - $player->cities);
    }

    /**
     * Architecture discounts one city per turn, and only as far as the treasury can pay: three
     * coins buy the full rebate, one coin buys a third of it.
     */
    private function architectureRebate(Player $player): int
    {
        if (!\in_array(self::ARCHITECTURE_ADVANCE, $player->advances, true)) {
            return 0;
        }

        return min($player->treasury, self::MAX_ARCHITECTURE_REBATE);
    }
}
