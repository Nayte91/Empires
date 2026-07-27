<?php

declare(strict_types=1);

namespace App\Tests\Game\Service;

use App\Entity\GameSession;
use App\Entity\Player;
use App\Game\GameData;
use App\Game\Service\StockCalculator;
use App\Game\Stat;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class StockCalculatorTest extends TestCase
{
    #[Test]
    public function whatIsLeftOnTheTableIsThePileMinusBothHolders(): void
    {
        $player = $this->createPlayer();
        $player->census = 20;
        $player->treasury = 15;

        $this->assertSame(55, $this->calculator()->pool());
        $this->assertSame(20, $this->calculator()->available($player));
    }

    /** Each holder is capped by what its twin already claimed. */
    #[Test]
    public function aStockHolderIsCeilingedByItsTwin(): void
    {
        $player = $this->createPlayer();
        $player->census = 20;
        $player->treasury = 15;

        $calculator = $this->calculator();

        $this->assertSame(35, $calculator->ceilingFor($player, Stat::Treasury));
        $this->assertSame(40, $calculator->ceilingFor($player, Stat::Census));
    }

    #[Test]
    public function aStatOutsideTheStockAnswersToItsOwnBound(): void
    {
        $player = $this->createPlayer();
        $player->census = 20;

        $calculator = $this->calculator();

        $this->assertFalse($calculator->drawsFromStock(Stat::Cities));
        $this->assertSame(Stat::Cities->max(), $calculator->ceilingFor($player, Stat::Cities));
        $this->assertSame(Stat::Cards->max(), $calculator->ceilingFor($player, Stat::Cards));
    }

    #[Test]
    public function bothHoldersAreRecognisedAsDrawingFromTheStock(): void
    {
        $calculator = $this->calculator();

        $this->assertTrue($calculator->drawsFromStock(Stat::Census));
        $this->assertTrue($calculator->drawsFromStock(Stat::Treasury));
    }

    private function calculator(): StockCalculator
    {
        return new StockCalculator(new GameData(\dirname(__DIR__, 3).'/config/game/game_data.yaml'));
    }

    private function createPlayer(): Player
    {
        return new Player(new GameSession(), 'Bob');
    }
}
