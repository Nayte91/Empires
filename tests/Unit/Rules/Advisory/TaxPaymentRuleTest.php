<?php

declare(strict_types=1);

namespace App\Tests\Unit\Rules\Advisory;

use App\State\Game;
use App\State\Player;
use App\Rules\Advisory\TaxPaymentRule;
use App\Rules\Advisory\Advisory;
use App\Rules\StockCalculator;
use App\Rules\TaxCalculator;
use App\Tests\Support\GameConfig;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class TaxPaymentRuleTest extends TestCase
{
    #[Test]
    public function sufficientStockYieldsNoAdvisory(): void
    {
        $player = new Player(new Game(), 'Bob');
        $player->cities = 3;
        $player->census = 1;
        $player->treasury = 0;

        $this->assertNotInstanceOf(\App\Rules\Advisory\Advisory::class, new TaxPaymentRule($this->tax())->evaluate($player));
    }

    #[Test]
    public function insufficientStockGetsCantPayTaxesAdvisory(): void
    {
        $player = new Player(new Game(), 'Bob');
        $player->cities = 3;
        $player->census = 30;
        $player->treasury = 30;

        $advisory = new TaxPaymentRule($this->tax())->evaluate($player);

        $this->assertInstanceOf(Advisory::class, $advisory);
        $this->assertSame("You can't pay your taxes!", $advisory->message);
    }

    #[Test]
    public function stockExactlyAtCityRequirementYieldsNoAdvisory(): void
    {
        $player = new Player(new Game(), 'Bob');
        $player->cities = 3;
        $player->census = 6;
        $player->treasury = 43;

        $this->assertNotInstanceOf(\App\Rules\Advisory\Advisory::class, new TaxPaymentRule($this->tax())->evaluate($player));
    }

    /** Democracy is outright immunity, so the warning must go even on a genuine shortfall. */
    #[Test]
    public function anImmunePlayerIsNeverWarnedDespiteAShortfall(): void
    {
        $player = new Player(new Game(), 'Bob');
        $player->cities = 3;
        $player->census = 30;
        $player->treasury = 30;
        $player->ownAdvances(['democracy']);

        $this->assertNotInstanceOf(Advisory::class, new TaxPaymentRule($this->tax())->evaluate($player));
    }

    private function tax(): TaxCalculator
    {
        return new TaxCalculator(new StockCalculator(GameConfig::gameData()), GameConfig::advanceEffects());
    }
}
