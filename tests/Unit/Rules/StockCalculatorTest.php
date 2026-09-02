<?php

declare(strict_types=1);

namespace App\Tests\Unit\Rules;

use App\Rules\Ruleset\GameRegistry;
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
        $player = PlayerBuilder::named('Bob')->withCensus(20)->withTreasury(15)->build();

        $this->assertSame(55, $this->calculator()->pool());
        $this->assertSame(20, $this->calculator()->available($player));
    }

    #[Test]
    public function aStockHolderIsCeilingedByItsTwin(): void
    {
        $player = PlayerBuilder::named('Bob')->withCensus(20)->withTreasury(15)->build();

        $calculator = $this->calculator();

        $this->assertSame(35, $calculator->ceilingFor($player, Stat::Treasury));
        $this->assertSame(40, $calculator->ceilingFor($player, Stat::Census));
    }

    #[Test]
    public function aStatOutsideTheStockCannotBeAskedItsCeilingHere(): void
    {
        $player = PlayerBuilder::named('Bob')->withCensus(20)->build();

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
        return new StockCalculator(new GameRegistry(\dirname(__DIR__, 3).'/config/game/game_data.yaml'));
    }
}
