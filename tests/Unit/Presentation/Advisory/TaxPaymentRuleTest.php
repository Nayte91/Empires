<?php

declare(strict_types=1);

namespace App\Tests\Unit\Presentation\Advisory;

use App\Presentation\Advisory\TaxPaymentRule;
use App\Presentation\Advisory\Advisory;
use App\Rules\StockCalculator;
use App\Rules\TaxCalculator;
use App\Tests\Support\GameConfig;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use App\Tests\Support\Fixture\PlayerBuilder;

final class TaxPaymentRuleTest extends TestCase
{
    #[Test]
    public function sufficientStockYieldsNoAdvisory(): void
    {
        $player = PlayerBuilder::named('Bob')->withCities(3)->withCensus(1)->withTreasury(0)->build();

        $this->assertNotInstanceOf(\App\Presentation\Advisory\Advisory::class, new TaxPaymentRule($this->tax())->evaluate($player));
    }

    #[Test]
    public function insufficientStockGetsCantPayTaxesAdvisory(): void
    {
        $player = PlayerBuilder::named('Bob')->withCities(3)->withCensus(30)->withTreasury(30)->build();

        $advisory = new TaxPaymentRule($this->tax())->evaluate($player);

        $this->assertInstanceOf(Advisory::class, $advisory);
        $this->assertSame("You can't pay your taxes!", $advisory->message);
    }

    #[Test]
    public function stockExactlyAtCityRequirementYieldsNoAdvisory(): void
    {
        $player = PlayerBuilder::named('Bob')->withCities(3)->withCensus(6)->withTreasury(43)->build();

        $this->assertNotInstanceOf(\App\Presentation\Advisory\Advisory::class, new TaxPaymentRule($this->tax())->evaluate($player));
    }

    #[Test]
    public function anImmunePlayerIsNeverWarnedDespiteAShortfall(): void
    {
        $player = PlayerBuilder::named('Bob')->withCities(3)->withCensus(30)->withTreasury(30)->withAdvances(['democracy'])->build();

        $this->assertNotInstanceOf(Advisory::class, new TaxPaymentRule($this->tax())->evaluate($player));
    }

    private function tax(): TaxCalculator
    {
        return new TaxCalculator(new StockCalculator(GameConfig::gameRegistry()), GameConfig::advanceEffects());
    }
}
