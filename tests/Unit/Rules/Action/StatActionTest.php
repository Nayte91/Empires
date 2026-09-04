<?php

declare(strict_types=1);

namespace App\Tests\Unit\Rules\Action;

use App\Rules\HandSizeCalculator;
use App\Rules\StatBoundsCalculator;
use App\Rules\StockCalculator;
use App\Rules\TaxCalculator;
use App\Rules\Action\Stat;
use App\Rules\Action\StatAction;
use App\Tests\Support\Fixture\PlayerBuilder;
use App\Tests\Support\GameConfig;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use App\Tests\Support\Fixture\GameBuilder;

final class StatActionTest extends TestCase
{
    #[Test]
    public function movingForwardAndBackwardWalksTheAstOneStepAtATime(): void
    {
        $player = PlayerBuilder::named('Bob')->withAstPosition(4)->build();

        StatAction::AstForward->apply($player, $this->hand(), $this->bounds(), $this->tax());

        $this->assertSame(5, $player->astPosition);

        StatAction::AstBackward->apply($player, $this->hand(), $this->bounds(), $this->tax());

        $this->assertSame(4, $player->astPosition);
    }

    #[Test]
    public function astMovesAreUnavailableAtTheTrackEnds(): void
    {
        $player = PlayerBuilder::named('Bob')->build();
        $bounds = $this->bounds();
        $player->astPosition = $bounds->floorFor($player, Stat::AstPosition);

        $this->assertFalse(StatAction::AstBackward->isAvailable($player, $this->hand(), $bounds, $this->tax()));
        $this->assertTrue(StatAction::AstForward->isAvailable($player, $this->hand(), $bounds, $this->tax()));

        $player->astPosition = $bounds->ceilingFor($player, Stat::AstPosition);

        $this->assertTrue(StatAction::AstBackward->isAvailable($player, $this->hand(), $bounds, $this->tax()));
        $this->assertFalse(StatAction::AstForward->isAvailable($player, $this->hand(), $bounds, $this->tax()));
    }

    #[Test]
    public function doublingTheCensusMultipliesItByTwo(): void
    {
        $player = PlayerBuilder::named('Bob')->withCensus(12)->build();

        StatAction::CensusDouble->apply($player, $this->hand(), $this->bounds(), $this->tax());

        $this->assertSame(24, $player->census);
    }

    #[Test]
    public function doublingTheCensusStopsAtWhatTheTreasuryLeavesInThePool(): void
    {
        $player = PlayerBuilder::named('Bob')->withCensus(20)->withTreasury(25)->build();

        StatAction::CensusDouble->apply($player, $this->hand(), $this->bounds(), $this->tax());

        $this->assertSame(30, $player->census);
    }

    #[Test]
    public function doublingIsUnavailableWhenThePoolLeavesNoRoom(): void
    {
        $player = PlayerBuilder::named('Bob')->withCensus(20)->withTreasury(35)->build();

        $this->assertFalse(StatAction::CensusDouble->isAvailable($player, $this->hand(), $this->bounds(), $this->tax()));

        StatAction::CensusDouble->apply($player, $this->hand(), $this->bounds(), $this->tax());

        $this->assertSame(20, $player->census);
    }

    #[Test]
    public function onlyTheStandardRateIsOfferedWithoutAdvances(): void
    {
        $player = PlayerBuilder::named('Bob')->build();
        $tax = $this->tax();

        $this->assertFalse(StatAction::PayTaxes1->isOffered($player, $tax));
        $this->assertTrue(StatAction::PayTaxes2->isOffered($player, $tax));
        $this->assertFalse(StatAction::PayTaxes3->isOffered($player, $tax));
        $this->assertFalse(StatAction::PayTaxes4->isOffered($player, $tax));
    }

    #[Test]
    public function coinageAndMonarchyTogetherOfferEveryRate(): void
    {
        $player = PlayerBuilder::named('Bob')->withAdvances(['coinage', 'monarchy'])->build();
        $tax = $this->tax();

        foreach ([StatAction::PayTaxes1, StatAction::PayTaxes2, StatAction::PayTaxes3, StatAction::PayTaxes4] as $action) {
            $this->assertTrue($action->isOffered($player, $tax), $action->value);
        }
    }

    #[Test]
    public function everyOtherActionIsAlwaysOnTheMenu(): void
    {
        $player = PlayerBuilder::named('Bob')->build();

        $this->assertTrue(StatAction::CensusDouble->isOffered($player, $this->tax()));
        $this->assertTrue(StatAction::BuildShip->isOffered($player, $this->tax()));
    }

    #[Test]
    public function payingTaxesCreditsTwoPerCity(): void
    {
        $player = PlayerBuilder::named('Bob')->withCities(4)->withTreasury(10)->build();

        StatAction::PayTaxes2->apply($player, $this->hand(), $this->bounds(), $this->tax());

        $this->assertSame(18, $player->treasury);
    }

    #[Test]
    public function payingTaxesStopsAtWhatTheCensusLeavesInThePool(): void
    {
        $player = PlayerBuilder::named('Bob')->withCities(9)->withCensus(40)->withTreasury(10)->build();

        StatAction::PayTaxes2->apply($player, $this->hand(), $this->bounds(), $this->tax());

        $this->assertSame(15, $player->treasury);
    }

    #[Test]
    public function buildingAShipCostsTwoTreasury(): void
    {
        $player = PlayerBuilder::named('Bob')->withShips(1)->withTreasury(7)->build();

        StatAction::BuildShip->apply($player, $this->hand(), $this->bounds(), $this->tax());

        $this->assertSame(2, $player->ships);
        $this->assertSame(5, $player->treasury);
    }

    #[Test]
    public function buildingAShipIsUnavailableAtTheFleetCapOrWithoutTheTwoTreasury(): void
    {
        $player = PlayerBuilder::named('Bob')->build();
        $bounds = $this->bounds();
        $player->ships = $bounds->ceilingFor($player, Stat::Ships);
        $player->treasury = 10;

        $this->assertFalse(StatAction::BuildShip->isAvailable($player, $this->hand(), $bounds, $this->tax()));

        $player->ships = 1;
        $player->treasury = 1;

        $this->assertFalse(StatAction::BuildShip->isAvailable($player, $this->hand(), $this->bounds(), $this->tax()));
    }

    #[Test]
    public function maintainingTheFleetCostsOneTreasuryPerShip(): void
    {
        $player = PlayerBuilder::named('Bob')->withShips(3)->withTreasury(10)->build();

        StatAction::MaintainShips->apply($player, $this->hand(), $this->bounds(), $this->tax());

        $this->assertSame(3, $player->ships);
        $this->assertSame(7, $player->treasury);
    }

    #[Test]
    public function maintainingIsUnavailableWithoutAFleetOrWithoutTheTreasuryToCoverIt(): void
    {
        $player = PlayerBuilder::named('Bob')->withShips(0)->withTreasury(10)->build();

        $this->assertFalse(StatAction::MaintainShips->isAvailable($player, $this->hand(), $this->bounds(), $this->tax()));

        $player->ships = 4;
        $player->treasury = 3;

        $this->assertFalse(StatAction::MaintainShips->isAvailable($player, $this->hand(), $this->bounds(), $this->tax()));

        $player->treasury = 4;

        $this->assertTrue(StatAction::MaintainShips->isAvailable($player, $this->hand(), $this->bounds(), $this->tax()));
    }

    #[Test]
    public function drawingAddsOneCardPerCity(): void
    {
        $player = PlayerBuilder::named('Bob')->withCities(5)->withCards(2)->build();

        StatAction::DrawCards->apply($player, $this->hand(), $this->bounds(), $this->tax());

        $this->assertSame(7, $player->cards);
    }

    #[Test]
    public function drawingIsUnavailableWithoutACity(): void
    {
        $player = PlayerBuilder::named('Bob')->withCities(0)->build();

        $this->assertFalse(StatAction::DrawCards->isAvailable($player, $this->hand(), $this->bounds(), $this->tax()));
    }

    #[Test]
    public function cuttingBringsTheHandDownToTheGamesLimit(): void
    {
        $player = PlayerBuilder::named('Bob')->in(GameBuilder::create()->withPlayerCount(12)->build())->withCards(14)->build();

        StatAction::CutToLimit->apply($player, $this->hand(), $this->bounds(), $this->tax());

        $this->assertSame(9, $player->cards);
    }

    #[Test]
    public function cuttingRespectsAnAdvanceThatRaisesTheLimit(): void
    {
        $player = PlayerBuilder::named('Bob')->withCards(14)->withAdvances(['roadbuilding'])->build();

        StatAction::CutToLimit->apply($player, $this->hand(), $this->bounds(), $this->tax());

        $this->assertSame(9, $player->cards);
    }

    #[Test]
    public function cuttingAHandAlreadyUnderTheLimitLeavesItUntouched(): void
    {
        $player = PlayerBuilder::named('Bob')->withCards(3)->build();

        $this->assertFalse(StatAction::CutToLimit->isAvailable($player, $this->hand(), $this->bounds(), $this->tax()));

        StatAction::CutToLimit->apply($player, $this->hand(), $this->bounds(), $this->tax());

        $this->assertSame(3, $player->cards);
    }

    private function hand(): HandSizeCalculator
    {
        return new HandSizeCalculator(GameConfig::gameRegistry(), GameConfig::advanceEffects());
    }

    private function stock(): StockCalculator
    {
        return new StockCalculator(GameConfig::gameRegistry());
    }

    private function bounds(): StatBoundsCalculator
    {
        return new StatBoundsCalculator(GameConfig::gameRegistry(), $this->stock(), GameConfig::astRegistry());
    }

    private function tax(): TaxCalculator
    {
        return new TaxCalculator($this->stock(), GameConfig::advanceEffects());
    }
}
