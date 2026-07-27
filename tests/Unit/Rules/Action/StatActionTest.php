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

final class StatActionTest extends TestCase
{
    #[Test]
    public function citiesOffersNoActionAndEveryOtherStatOffersItsOwn(): void
    {
        $this->assertSame([], StatAction::forStat(Stat::Cities));
        $this->assertSame([StatAction::AstBackward, StatAction::AstForward], StatAction::forStat(Stat::AstPosition));
        $this->assertSame([StatAction::CensusDouble], StatAction::forStat(Stat::Census));
        $this->assertSame(
            [StatAction::PayTaxes1, StatAction::PayTaxes2, StatAction::PayTaxes3, StatAction::PayTaxes4],
            StatAction::forStat(Stat::Treasury),
        );
        $this->assertSame([StatAction::BuildShip, StatAction::MaintainShips], StatAction::forStat(Stat::Ships));
        $this->assertSame([StatAction::DrawCards, StatAction::CutToLimit], StatAction::forStat(Stat::Cards));
    }

    #[Test]
    public function movingForwardAndBackwardWalksTheAstOneStepAtATime(): void
    {
        $player = PlayerBuilder::named('Bob')->build();
        $player->astPosition = 4;

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
        $player = PlayerBuilder::named('Bob')->build();
        $player->census = 12;

        StatAction::CensusDouble->apply($player, $this->hand(), $this->bounds(), $this->tax());

        $this->assertSame(24, $player->census);
    }

    /**
     * Census and treasury share one 55-token stock, so doubling can only claim what the treasury
     * has left on the table.
     */
    #[Test]
    public function doublingTheCensusStopsAtWhatTheTreasuryLeavesInThePool(): void
    {
        $player = PlayerBuilder::named('Bob')->build();
        $player->census = 20;
        $player->treasury = 25;

        StatAction::CensusDouble->apply($player, $this->hand(), $this->bounds(), $this->tax());

        $this->assertSame(30, $player->census);
    }

    #[Test]
    public function doublingIsUnavailableWhenThePoolLeavesNoRoom(): void
    {
        $player = PlayerBuilder::named('Bob')->build();
        $player->census = 20;
        $player->treasury = 35;

        $this->assertFalse(StatAction::CensusDouble->isAvailable($player, $this->hand(), $this->bounds(), $this->tax()));

        StatAction::CensusDouble->apply($player, $this->hand(), $this->bounds(), $this->tax());

        $this->assertSame(20, $player->census);
    }

    /** A rate is only offered when the player's advances unlock it. */
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
        $player = PlayerBuilder::named('Bob')->build();
        $player->ownAdvances(['coinage', 'monarchy']);
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
        $player = PlayerBuilder::named('Bob')->build();
        $player->cities = 4;
        $player->treasury = 10;

        StatAction::PayTaxes2->apply($player, $this->hand(), $this->bounds(), $this->tax());

        $this->assertSame(18, $player->treasury);
    }

    #[Test]
    public function payingTaxesStopsAtWhatTheCensusLeavesInThePool(): void
    {
        $player = PlayerBuilder::named('Bob')->build();
        $player->cities = 9;
        $player->census = 40;
        $player->treasury = 10;

        StatAction::PayTaxes2->apply($player, $this->hand(), $this->bounds(), $this->tax());

        $this->assertSame(15, $player->treasury);
    }

    #[Test]
    public function buildingAShipCostsTwoTreasury(): void
    {
        $player = PlayerBuilder::named('Bob')->build();
        $player->ships = 1;
        $player->treasury = 7;

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
        $player = PlayerBuilder::named('Bob')->build();
        $player->ships = 3;
        $player->treasury = 10;

        StatAction::MaintainShips->apply($player, $this->hand(), $this->bounds(), $this->tax());

        $this->assertSame(3, $player->ships);
        $this->assertSame(7, $player->treasury);
    }

    #[Test]
    public function maintainingIsUnavailableWithoutAFleetOrWithoutTheTreasuryToCoverIt(): void
    {
        $player = PlayerBuilder::named('Bob')->build();
        $player->ships = 0;
        $player->treasury = 10;

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
        $player = PlayerBuilder::named('Bob')->build();
        $player->cities = 5;
        $player->cards = 2;

        StatAction::DrawCards->apply($player, $this->hand(), $this->bounds(), $this->tax());

        $this->assertSame(7, $player->cards);
    }

    #[Test]
    public function drawingIsUnavailableWithoutACity(): void
    {
        $player = PlayerBuilder::named('Bob')->build();
        $player->cities = 0;

        $this->assertFalse(StatAction::DrawCards->isAvailable($player, $this->hand(), $this->bounds(), $this->tax()));
    }

    #[Test]
    public function cuttingBringsTheHandDownToTheGamesLimit(): void
    {
        $player = PlayerBuilder::named('Bob')->build();
        $player->game->playerCount = 12;
        $player->cards = 14;

        StatAction::CutToLimit->apply($player, $this->hand(), $this->bounds(), $this->tax());

        $this->assertSame(9, $player->cards);
    }

    /** Road Building raises the limit, so the same hand is cut one card less far. */
    #[Test]
    public function cuttingRespectsAnAdvanceThatRaisesTheLimit(): void
    {
        $player = PlayerBuilder::named('Bob')->build();
        $player->cards = 14;
        $player->ownAdvances(['roadbuilding']);

        StatAction::CutToLimit->apply($player, $this->hand(), $this->bounds(), $this->tax());

        $this->assertSame(9, $player->cards);
    }

    /**
     * The action name reaches the server as a client-writable prop, so a hand already under the
     * limit must not be topped up to it.
     */
    #[Test]
    public function cuttingAHandAlreadyUnderTheLimitLeavesItUntouched(): void
    {
        $player = PlayerBuilder::named('Bob')->build();
        $player->cards = 3;

        $this->assertFalse(StatAction::CutToLimit->isAvailable($player, $this->hand(), $this->bounds(), $this->tax()));

        StatAction::CutToLimit->apply($player, $this->hand(), $this->bounds(), $this->tax());

        $this->assertSame(3, $player->cards);
    }

    private function hand(): HandSizeCalculator
    {
        return new HandSizeCalculator(GameConfig::gameData(), GameConfig::advanceEffects());
    }

    private function stock(): StockCalculator
    {
        return new StockCalculator(GameConfig::gameData());
    }

    private function bounds(): StatBoundsCalculator
    {
        return new StatBoundsCalculator(GameConfig::gameData(), $this->stock(), GameConfig::astCatalog());
    }

    private function tax(): TaxCalculator
    {
        return new TaxCalculator($this->stock(), GameConfig::advanceEffects());
    }
}
