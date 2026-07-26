<?php

declare(strict_types=1);

namespace App\Tests\Game;

use App\Entity\GameSession;
use App\Entity\Player;
use App\Game\Stat;
use App\Game\StatAction;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class StatActionTest extends TestCase
{
    private const int HAND_LIMIT = 8;

    #[Test]
    public function citiesOffersNoActionAndEveryOtherStatOffersItsOwn(): void
    {
        $this->assertSame([], StatAction::forStat(Stat::Cities));
        $this->assertSame([StatAction::AstBackward, StatAction::AstForward], StatAction::forStat(Stat::AstPosition));
        $this->assertSame([StatAction::CensusDouble], StatAction::forStat(Stat::Census));
        $this->assertSame([StatAction::PayTaxes], StatAction::forStat(Stat::Treasury));
        $this->assertSame([StatAction::BuildShip, StatAction::MaintainShips], StatAction::forStat(Stat::Ships));
        $this->assertSame([StatAction::DrawCards, StatAction::CutToLimit], StatAction::forStat(Stat::Cards));
    }

    #[Test]
    public function movingForwardAndBackwardWalksTheAstOneStepAtATime(): void
    {
        $player = $this->createPlayer();
        $player->astPosition = 4;

        StatAction::AstForward->apply($player, self::HAND_LIMIT);

        $this->assertSame(5, $player->astPosition);

        StatAction::AstBackward->apply($player, self::HAND_LIMIT);

        $this->assertSame(4, $player->astPosition);
    }

    #[Test]
    public function astMovesAreUnavailableAtTheTrackEnds(): void
    {
        $player = $this->createPlayer();
        $player->astPosition = Player::AST_MIN;

        $this->assertFalse(StatAction::AstBackward->isAvailable($player, self::HAND_LIMIT));
        $this->assertTrue(StatAction::AstForward->isAvailable($player, self::HAND_LIMIT));

        $player->astPosition = Player::AST_MAX;

        $this->assertTrue(StatAction::AstBackward->isAvailable($player, self::HAND_LIMIT));
        $this->assertFalse(StatAction::AstForward->isAvailable($player, self::HAND_LIMIT));
    }

    #[Test]
    public function doublingTheCensusMultipliesItByTwo(): void
    {
        $player = $this->createPlayer();
        $player->census = 12;

        StatAction::CensusDouble->apply($player, self::HAND_LIMIT);

        $this->assertSame(24, $player->census);
    }

    /**
     * Census and treasury share one 55-token stock, so doubling can only claim what the treasury
     * has left on the table.
     */
    #[Test]
    public function doublingTheCensusStopsAtWhatTheTreasuryLeavesInThePool(): void
    {
        $player = $this->createPlayer();
        $player->census = 20;
        $player->treasury = 25;

        StatAction::CensusDouble->apply($player, self::HAND_LIMIT);

        $this->assertSame(30, $player->census);
    }

    #[Test]
    public function doublingIsUnavailableWhenThePoolLeavesNoRoom(): void
    {
        $player = $this->createPlayer();
        $player->census = 20;
        $player->treasury = 35;

        $this->assertFalse(StatAction::CensusDouble->isAvailable($player, self::HAND_LIMIT));

        StatAction::CensusDouble->apply($player, self::HAND_LIMIT);

        $this->assertSame(20, $player->census);
    }

    #[Test]
    public function payingTaxesCreditsTwoPerCity(): void
    {
        $player = $this->createPlayer();
        $player->cities = 4;
        $player->treasury = 10;

        StatAction::PayTaxes->apply($player, self::HAND_LIMIT);

        $this->assertSame(18, $player->treasury);
    }

    #[Test]
    public function payingTaxesStopsAtWhatTheCensusLeavesInThePool(): void
    {
        $player = $this->createPlayer();
        $player->cities = 9;
        $player->census = 40;
        $player->treasury = 10;

        StatAction::PayTaxes->apply($player, self::HAND_LIMIT);

        $this->assertSame(15, $player->treasury);
    }

    #[Test]
    public function buildingAShipCostsTwoTreasury(): void
    {
        $player = $this->createPlayer();
        $player->ships = 1;
        $player->treasury = 7;

        StatAction::BuildShip->apply($player, self::HAND_LIMIT);

        $this->assertSame(2, $player->ships);
        $this->assertSame(5, $player->treasury);
    }

    #[Test]
    public function buildingAShipIsUnavailableAtTheFleetCapOrWithoutTheTwoTreasury(): void
    {
        $player = $this->createPlayer();
        $player->ships = Player::SHIPS_MAX;
        $player->treasury = 10;

        $this->assertFalse(StatAction::BuildShip->isAvailable($player, self::HAND_LIMIT));

        $player->ships = 1;
        $player->treasury = 1;

        $this->assertFalse(StatAction::BuildShip->isAvailable($player, self::HAND_LIMIT));
    }

    #[Test]
    public function maintainingTheFleetCostsOneTreasuryPerShip(): void
    {
        $player = $this->createPlayer();
        $player->ships = 3;
        $player->treasury = 10;

        StatAction::MaintainShips->apply($player, self::HAND_LIMIT);

        $this->assertSame(3, $player->ships);
        $this->assertSame(7, $player->treasury);
    }

    #[Test]
    public function maintainingIsUnavailableWithoutAFleetOrWithoutTheTreasuryToCoverIt(): void
    {
        $player = $this->createPlayer();
        $player->ships = 0;
        $player->treasury = 10;

        $this->assertFalse(StatAction::MaintainShips->isAvailable($player, self::HAND_LIMIT));

        $player->ships = 4;
        $player->treasury = 3;

        $this->assertFalse(StatAction::MaintainShips->isAvailable($player, self::HAND_LIMIT));

        $player->treasury = 4;

        $this->assertTrue(StatAction::MaintainShips->isAvailable($player, self::HAND_LIMIT));
    }

    #[Test]
    public function drawingAddsOneCardPerCity(): void
    {
        $player = $this->createPlayer();
        $player->cities = 5;
        $player->cards = 2;

        StatAction::DrawCards->apply($player, self::HAND_LIMIT);

        $this->assertSame(7, $player->cards);
    }

    #[Test]
    public function drawingIsUnavailableWithoutACity(): void
    {
        $player = $this->createPlayer();
        $player->cities = 0;

        $this->assertFalse(StatAction::DrawCards->isAvailable($player, self::HAND_LIMIT));
    }

    #[Test]
    public function cuttingBringsTheHandDownToTheGamesLimit(): void
    {
        $player = $this->createPlayer();
        $player->cards = 14;

        StatAction::CutToLimit->apply($player, 9);

        $this->assertSame(9, $player->cards);
    }

    /**
     * The action name reaches the server as a client-writable prop, so a hand already under the
     * limit must not be topped up to it.
     */
    #[Test]
    public function cuttingAHandAlreadyUnderTheLimitLeavesItUntouched(): void
    {
        $player = $this->createPlayer();
        $player->cards = 3;

        $this->assertFalse(StatAction::CutToLimit->isAvailable($player, self::HAND_LIMIT));

        StatAction::CutToLimit->apply($player, self::HAND_LIMIT);

        $this->assertSame(3, $player->cards);
    }

    private function createPlayer(): Player
    {
        return new Player(new GameSession(), 'Bob');
    }
}
