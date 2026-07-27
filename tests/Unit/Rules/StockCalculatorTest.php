<?php

declare(strict_types=1);

namespace App\Tests\Unit\Rules;

use App\Rules\Ruleset\GameData;
use App\Rules\StockCalculator;
use App\Rules\Action\Stat;
use App\Tests\Support\Fixture\PlayerBuilder;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class StockCalculatorTest extends TestCase
{
    #[Test]
    public function whatIsLeftOnTheTableIsThePileMinusBothHolders(): void
    {
        $player = PlayerBuilder::named('Bob')->build();
        $player->census = 20;
        $player->treasury = 15;

        $this->assertSame(55, $this->calculator()->pool());
        $this->assertSame(20, $this->calculator()->available($player));
    }

    /** Each holder is capped by what its twin already claimed. */
    #[Test]
    public function aStockHolderIsCeilingedByItsTwin(): void
    {
        $player = PlayerBuilder::named('Bob')->build();
        $player->census = 20;
        $player->treasury = 15;

        $calculator = $this->calculator();

        $this->assertSame(35, $calculator->ceilingFor($player, Stat::Treasury));
        $this->assertSame(40, $calculator->ceilingFor($player, Stat::Census));
    }

    /** A stat outside the stock is not this calculator's concern — see StatBoundsCalculator. */
    #[Test]
    public function aStatOutsideTheStockCannotBeAskedItsCeilingHere(): void
    {
        $player = PlayerBuilder::named('Bob')->build();
        $player->census = 20;

        $calculator = $this->calculator();

        $this->assertFalse($calculator->drawsFromStock(Stat::Cities));

        $this->expectException(\LogicException::class);

        $calculator->ceilingFor($player, Stat::Cities);
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
}
