<?php

declare(strict_types=1);

namespace App\Tests\Unit\Rules;

use App\Rules\CityBuildCalculator;
use App\Rules\CitySupportCalculator;
use App\Tests\Support\Fixture\PlayerBuilder;
use App\Tests\Support\GameConfig;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class CityBuildCalculatorTest extends TestCase
{
    #[Test]
    public function eachCityCostsItsBuildPriceAndItsSupportFloor(): void
    {
        $player = PlayerBuilder::named('Bob')->withCities(3)->withCensus(20)->build();

        $this->assertSame(1, $this->calculator()->affordableCities($player));
    }

    #[Test]
    public function aWiderMarginBuysProportionallyMoreCities(): void
    {
        $player = PlayerBuilder::named('Bob')->withCities(2)->withCensus(30)->build();

        $this->assertSame(3, $this->calculator()->affordableCities($player));
    }

    #[Test]
    public function anUnderSupportedPlayerCanFoundNothing(): void
    {
        $player = PlayerBuilder::named('Bob')->withCities(5)->withCensus(4)->build();

        $this->assertSame(0, $this->calculator()->affordableCities($player));
    }

    #[Test]
    public function theNineCityLimitCapsWhatTheMarginWouldAllow(): void
    {
        $player = PlayerBuilder::named('Bob')->withCities(8)->withCensus(55)->build();

        $this->assertSame(1, $this->calculator()->affordableCities($player));
        $this->assertSame(1, $this->calculator()->remainingCitySlots($player));
    }

    #[Test]
    public function afullEmpireHasNoSlotLeft(): void
    {
        $player = PlayerBuilder::named('Bob')->withCities(9)->withCensus(55)->build();

        $this->assertSame(0, $this->calculator()->remainingCitySlots($player));
        $this->assertSame(0, $this->calculator()->affordableCities($player));
    }

    #[Test]
    public function eachArchitectureCoinBuysOnePopulationOffThePrice(): void
    {
        $calculator = $this->calculator();

        foreach ([1 => 7, 2 => 6, 3 => 5] as $coins => $census) {
            $player = PlayerBuilder::named('Bob')->withAdvances(['architecture'])->withCensus($census)->build();

            $player->treasury = $coins - 1;
            $this->assertSame(0, $calculator->affordableCities($player), sprintf('%d coins', $coins - 1));

            $player->treasury = $coins;
            $this->assertSame(1, $calculator->affordableCities($player), sprintf('%d coins', $coins));
        }
    }

    #[Test]
    public function aFourthCoinBuysNoFurtherRebate(): void
    {
        $player = PlayerBuilder::named('Bob')->withAdvances(['architecture'])->withCensus(4)->withTreasury(20)->build();

        $this->assertSame(0, $this->calculator()->affordableCities($player));
    }

    #[Test]
    public function withoutArchitectureTheTreasuryBuysNothing(): void
    {
        $player = PlayerBuilder::named('Bob')->withCensus(7)->withTreasury(20)->build();

        $this->assertSame(0, $this->calculator()->affordableCities($player));
    }

    #[Test]
    public function theRebateCanBuyOneMoreCity(): void
    {
        $player = PlayerBuilder::named('Bob')->withCities(3)->withCensus(20)->withTreasury(3)->build();

        $this->assertSame(1, $this->calculator()->affordableCities($player));

        $player->ownAdvances(['architecture']);

        $this->assertSame(2, $this->calculator()->affordableCities($player));
    }

    private function calculator(): CityBuildCalculator
    {
        return new CityBuildCalculator(
            new CitySupportCalculator(),
            GameConfig::gameRegistry(),
            GameConfig::advanceEffects(),
        );
    }
}
