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
        $player = PlayerBuilder::named('Bob')->build();
        $player->cities = 8;

        $calculator = $this->tax();

        $this->assertSame([2], $calculator->rates($player));
        $this->assertSame(16, $calculator->billAt($player, 2));
    }

    /** Coinage moves the rate either way; Monarchy only raises it; together they span 1 to 4. */
    #[Test]
    public function theAdvancesWidenTheRateIntoAChoice(): void
    {
        $calculator = $this->tax();

        $coinage = PlayerBuilder::named('Bob')->build();
        $coinage->ownAdvances(['coinage']);
        $this->assertSame([1, 2, 3], $calculator->rates($coinage));

        $monarchy = PlayerBuilder::named('Bob')->build();
        $monarchy->ownAdvances(['monarchy']);
        $this->assertSame([2, 3], $calculator->rates($monarchy));

        $both = PlayerBuilder::named('Bob')->build();
        $both->ownAdvances(['coinage', 'monarchy']);
        $this->assertSame([1, 2, 3, 4], $calculator->rates($both));
    }

    /**
     * Only the floor reaches the alarms: a player who may elect to pay less needs less stock to
     * stay out of trouble.
     */
    #[Test]
    public function coinageLowersTheStockAPlayerMustRecover(): void
    {
        $player = PlayerBuilder::named('Bob')->build();
        $player->cities = 8;
        $player->census = 30;
        $player->treasury = 15;

        $calculator = $this->tax();

        $this->assertSame(6, $calculator->stockToRecover($player));
        $this->assertTrue($calculator->citiesRevolt($player));

        $player->ownAdvances(['coinage']);

        $this->assertSame(0, $calculator->stockToRecover($player));
        $this->assertFalse($calculator->citiesRevolt($player));
    }

    /** Monarchy only raises the rate, so it can never rescue a player from a shortage. */
    #[Test]
    public function monarchyLeavesTheShortageWhereItWas(): void
    {
        $player = PlayerBuilder::named('Bob')->build();
        $player->cities = 8;
        $player->census = 30;
        $player->treasury = 15;
        $player->ownAdvances(['monarchy']);

        $this->assertSame(6, $this->tax()->stockToRecover($player));
    }

    #[Test]
    public function availableStockIsWhatNeitherTheCensusNorTheTreasuryHolds(): void
    {
        $player = PlayerBuilder::named('Bob')->build();
        $player->census = 30;
        $player->treasury = 15;

        $this->assertSame(10, $this->tax()->availableStock($player));
    }

    #[Test]
    public function stockToRecoverIsWhatTheBillExceedsTheAvailableStock(): void
    {
        $player = PlayerBuilder::named('Bob')->build();
        $player->cities = 8;
        $player->census = 30;
        $player->treasury = 15;

        $this->assertSame(6, $this->tax()->stockToRecover($player));
    }

    #[Test]
    public function aPlayerWhoseStockCoversTheBillKeepsTheirCities(): void
    {
        $player = PlayerBuilder::named('Bob')->build();
        $player->cities = 3;
        $player->census = 10;
        $player->treasury = 10;

        $this->assertSame(0, $this->tax()->stockToRecover($player));
        $this->assertFalse($this->tax()->citiesRevolt($player));
    }

    /**
     * The whole point of the immunity: the arithmetic still reports a real shortfall, and the
     * cities still do not revolt. Every consumer must read citiesRevolt(), never the figure alone.
     */
    #[Test]
    public function immunityStopsTheRevoltWithoutClearingTheShortfall(): void
    {
        $player = PlayerBuilder::named('Bob')->build();
        $player->cities = 8;
        $player->census = 30;
        $player->treasury = 15;
        $player->ownAdvances(['democracy']);

        $calculator = $this->tax();

        $this->assertTrue($calculator->isImmune($player));
        $this->assertSame(6, $calculator->stockToRecover($player));
        $this->assertFalse($calculator->citiesRevolt($player));
    }

    #[Test]
    public function anOrdinaryPlayerShortOfStockSeesTheirCitiesRevolt(): void
    {
        $player = PlayerBuilder::named('Bob')->build();
        $player->cities = 8;
        $player->census = 30;
        $player->treasury = 15;

        $this->assertFalse($this->tax()->isImmune($player));
        $this->assertTrue($this->tax()->citiesRevolt($player));
    }

    #[Test]
    public function collectingCreditsTheChosenRateToTheTreasury(): void
    {
        $player = PlayerBuilder::named('Bob')->build();
        $player->cities = 4;
        $player->treasury = 10;

        $calculator = $this->tax();

        $this->assertSame(14, $calculator->collectedAt($player, 1));
        $this->assertSame(18, $calculator->collectedAt($player, 2));
        $this->assertSame(22, $calculator->collectedAt($player, 3));
    }

    #[Test]
    public function collectingStopsAtWhatTheCensusLeavesInThePool(): void
    {
        $player = PlayerBuilder::named('Bob')->build();
        $player->cities = 9;
        $player->census = 40;
        $player->treasury = 10;

        $this->assertSame(15, $this->tax()->collectedAt($player, 2));
    }

    /** Immunity covers the consequence of not paying, never the collection itself. */
    #[Test]
    public function immunityDoesNotChangeWhatIsCollected(): void
    {
        $player = PlayerBuilder::named('Bob')->build();
        $player->cities = 4;
        $player->treasury = 10;
        $player->ownAdvances(['democracy']);

        $this->assertSame(18, $this->tax()->collectedAt($player, 2));
    }

    private function tax(): TaxCalculator
    {
        return new TaxCalculator(new StockCalculator(GameConfig::gameData()), GameConfig::advanceEffects());
    }
}
