<?php

declare(strict_types=1);

namespace App\Tests\Unit\Presentation\Advisory;

use App\Presentation\Advisory\AdvisoryLevel;
use App\Presentation\Advisory\TaxStockRule;
use App\Rules\StockCalculator;
use App\Rules\TaxCalculator;
use App\Tests\Support\Fixture\PlayerBuilder;
use App\Tests\Support\GameConfig;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class TaxStockRuleTest extends TestCase
{
    #[Test]
    public function aShortfallIsStatedAsAnAmountToRecover(): void
    {
        $player = PlayerBuilder::named('Bob')->withCities(8)->withCensus(30)->withTreasury(15)->build();

        $advisory = $this->rule()->evaluate($player);

        $this->assertSame('You must recover 6 stock to pay your taxes', $advisory->message);
        $this->assertSame(AdvisoryLevel::Caution, $advisory->level);
    }

    #[Test]
    public function acoveredBillIsStatedAsReassurance(): void
    {
        $player = PlayerBuilder::named('Bob')->withCities(3)->withCensus(10)->withTreasury(10)->build();

        $this->assertSame('Your stock covers your taxes', $this->rule()->evaluate($player)->message);
    }

    #[Test]
    public function immunityIsStatedEvenOnARealShortfall(): void
    {
        $player = PlayerBuilder::named('Bob')->withCities(8)->withCensus(30)->withTreasury(15)->withAdvances(['democracy'])->build();

        $this->assertSame('Your cities never revolt over taxes', $this->rule()->evaluate($player)->message);
    }

    private function rule(): TaxStockRule
    {
        return new TaxStockRule($this->tax());
    }

    private function tax(): TaxCalculator
    {
        return new TaxCalculator(new StockCalculator(GameConfig::gameRegistry()), GameConfig::advanceEffects());
    }
}
