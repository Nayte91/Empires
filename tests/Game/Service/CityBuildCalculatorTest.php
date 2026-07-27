<?php

declare(strict_types=1);

namespace App\Tests\Game\Service;

use App\Entity\GameSession;
use App\Entity\Player;
use App\Game\GameData;
use App\Game\Service\CityBuildCalculator;
use App\Game\Service\CitySupportCalculator;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class CityBuildCalculatorTest extends TestCase
{
    /**
     * A city costs 6 population to raise and 2 more to keep standing, so 20 population over 3
     * cities buys one city, not the two a build-cost-only count would promise.
     */
    #[Test]
    public function eachCityCostsItsBuildPriceAndItsSupportFloor(): void
    {
        $player = $this->createPlayer();
        $player->cities = 3;
        $player->census = 20;

        $this->assertSame(1, $this->calculator()->affordableCities($player));
    }

    #[Test]
    public function aWiderMarginBuysProportionallyMoreCities(): void
    {
        $player = $this->createPlayer();
        $player->cities = 2;
        $player->census = 30;

        $this->assertSame(3, $this->calculator()->affordableCities($player));
    }

    #[Test]
    public function anUnderSupportedPlayerCanFoundNothing(): void
    {
        $player = $this->createPlayer();
        $player->cities = 5;
        $player->census = 4;

        $this->assertSame(0, $this->calculator()->affordableCities($player));
    }

    #[Test]
    public function theNineCityLimitCapsWhatTheMarginWouldAllow(): void
    {
        $player = $this->createPlayer();
        $player->cities = 8;
        $player->census = 55;

        $this->assertSame(1, $this->calculator()->affordableCities($player));
        $this->assertSame(1, $this->calculator()->remainingCitySlots($player));
    }

    #[Test]
    public function afullEmpireHasNoSlotLeft(): void
    {
        $player = $this->createPlayer();
        $player->cities = 9;
        $player->census = 55;

        $this->assertSame(0, $this->calculator()->remainingCitySlots($player));
        $this->assertSame(0, $this->calculator()->affordableCities($player));
    }

    /**
     * Each coin buys exactly one population off the price, so each is checked on the margin where
     * it alone tips a city from unaffordable to affordable.
     */
    #[Test]
    public function eachArchitectureCoinBuysOnePopulationOffThePrice(): void
    {
        $calculator = $this->calculator();

        foreach ([1 => 7, 2 => 6, 3 => 5] as $coins => $census) {
            $player = $this->createPlayer();
            $player->ownAdvances([CityBuildCalculator::ARCHITECTURE_ADVANCE]);
            $player->census = $census;

            $player->treasury = $coins - 1;
            $this->assertSame(0, $calculator->affordableCities($player), sprintf('%d coins', $coins - 1));

            $player->treasury = $coins;
            $this->assertSame(1, $calculator->affordableCities($player), sprintf('%d coins', $coins));
        }
    }

    #[Test]
    public function aFourthCoinBuysNoFurtherRebate(): void
    {
        $player = $this->createPlayer();
        $player->ownAdvances([CityBuildCalculator::ARCHITECTURE_ADVANCE]);
        $player->census = 4;
        $player->treasury = 20;

        $this->assertSame(0, $this->calculator()->affordableCities($player));
    }

    #[Test]
    public function withoutArchitectureTheTreasuryBuysNothing(): void
    {
        $player = $this->createPlayer();
        $player->census = 7;
        $player->treasury = 20;

        $this->assertSame(0, $this->calculator()->affordableCities($player));
    }

    /** The rebate can tip the count: the same margin buys a second city once a city is cheaper. */
    #[Test]
    public function theRebateCanBuyOneMoreCity(): void
    {
        $player = $this->createPlayer();
        $player->cities = 3;
        $player->census = 20;
        $player->treasury = 3;

        $this->assertSame(1, $this->calculator()->affordableCities($player));

        $player->ownAdvances([CityBuildCalculator::ARCHITECTURE_ADVANCE]);

        $this->assertSame(2, $this->calculator()->affordableCities($player));
    }

    private function calculator(): CityBuildCalculator
    {
        return new CityBuildCalculator(
            new CitySupportCalculator(),
            new GameData(\dirname(__DIR__, 3).'/config/game/game_data.yaml'),
        );
    }

    private function createPlayer(): Player
    {
        return new Player(new GameSession(), 'Bob');
    }
}
