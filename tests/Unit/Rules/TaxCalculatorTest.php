<?php

declare(strict_types=1);

namespace App\Tests\Unit\Rules;

use App\Rules\StockCalculator;
use App\Rules\TaxCalculator;
use App\Tests\Support\Fixture\PlayerBuilder;
use App\Tests\Support\GameConfig;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class TaxCalculatorTest extends TestCase
{
    #[Test]
    public function theStandardRateIsTwoPerCity(): void
    {
        $player = PlayerBuilder::named('Bob')->withCities(8)->build();

        $calculator = $this->tax();

        $this->assertSame([2], $calculator->rates($player));
        $this->assertSame(16, $calculator->billAt($player, 2));
    }

    #[Test]
    public function theAdvancesWidenTheRateIntoAChoice(): void
    {
        $calculator = $this->tax();

        $coinage = PlayerBuilder::named('Bob')->withAdvances(['coinage'])->build();
        $this->assertSame([1, 2, 3], $calculator->rates($coinage));

        $monarchy = PlayerBuilder::named('Bob')->withAdvances(['monarchy'])->build();
        $this->assertSame([2, 3], $calculator->rates($monarchy));

        $both = PlayerBuilder::named('Bob')->withAdvances(['coinage', 'monarchy'])->build();
        $this->assertSame([1, 2, 3, 4], $calculator->rates($both));
    }

    #[Test]
    public function coinageLowersTheStockAPlayerMustRecover(): void
    {
        $player = PlayerBuilder::named('Bob')->withCities(8)->withCensus(30)->withTreasury(15)->build();

        $calculator = $this->tax();

        $this->assertSame(6, $calculator->stockToRecover($player));
        $this->assertTrue($calculator->citiesRevolt($player));

        $player->ownAdvances(['coinage']);

        $this->assertSame(0, $calculator->stockToRecover($player));
        $this->assertFalse($calculator->citiesRevolt($player));
    }

    #[Test]
    public function monarchyLeavesTheShortageWhereItWas(): void
    {
        $player = PlayerBuilder::named('Bob')->withCities(8)->withCensus(30)->withTreasury(15)->withAdvances(['monarchy'])->build();

        $this->assertSame(6, $this->tax()->stockToRecover($player));
    }

    #[Test]
    public function availableStockIsWhatNeitherTheCensusNorTheTreasuryHolds(): void
    {
        $player = PlayerBuilder::named('Bob')->withCensus(30)->withTreasury(15)->build();

        $this->assertSame(10, $this->tax()->availableStock($player));
    }

    #[Test]
    public function stockToRecoverIsWhatTheBillExceedsTheAvailableStock(): void
    {
        $player = PlayerBuilder::named('Bob')->withCities(8)->withCensus(30)->withTreasury(15)->build();

        $this->assertSame(6, $this->tax()->stockToRecover($player));
    }

    #[Test]
    public function aPlayerWhoseStockCoversTheBillKeepsTheirCities(): void
    {
        $player = PlayerBuilder::named('Bob')->withCities(3)->withCensus(10)->withTreasury(10)->build();

        $this->assertSame(0, $this->tax()->stockToRecover($player));
        $this->assertFalse($this->tax()->citiesRevolt($player));
    }

    #[Test]
    public function immunityStopsTheRevoltWithoutClearingTheShortfall(): void
    {
        $player = PlayerBuilder::named('Bob')->withCities(8)->withCensus(30)->withTreasury(15)->withAdvances(['democracy'])->build();

        $calculator = $this->tax();

        $this->assertTrue($calculator->isImmune($player));
        $this->assertSame(6, $calculator->stockToRecover($player));
        $this->assertFalse($calculator->citiesRevolt($player));
    }

    #[Test]
    public function anOrdinaryPlayerShortOfStockSeesTheirCitiesRevolt(): void
    {
        $player = PlayerBuilder::named('Bob')->withCities(8)->withCensus(30)->withTreasury(15)->build();

        $this->assertFalse($this->tax()->isImmune($player));
        $this->assertTrue($this->tax()->citiesRevolt($player));
    }

    #[Test]
    public function collectingCreditsTheChosenRateToTheTreasury(): void
    {
        $player = PlayerBuilder::named('Bob')->withCities(4)->withTreasury(10)->build();

        $calculator = $this->tax();

        $this->assertSame(14, $calculator->collectedAt($player, 1));
        $this->assertSame(18, $calculator->collectedAt($player, 2));
        $this->assertSame(22, $calculator->collectedAt($player, 3));
    }

    #[Test]
    public function collectingStopsAtWhatTheCensusLeavesInThePool(): void
    {
        $player = PlayerBuilder::named('Bob')->withCities(9)->withCensus(40)->withTreasury(10)->build();

        $this->assertSame(15, $this->tax()->collectedAt($player, 2));
    }

    #[Test]
    public function immunityDoesNotChangeWhatIsCollected(): void
    {
        $player = PlayerBuilder::named('Bob')->withCities(4)->withTreasury(10)->withAdvances(['democracy'])->build();

        $this->assertSame(18, $this->tax()->collectedAt($player, 2));
    }

    private function tax(): TaxCalculator
    {
        return new TaxCalculator(new StockCalculator(GameConfig::gameRegistry()), GameConfig::advanceEffects());
    }
}
