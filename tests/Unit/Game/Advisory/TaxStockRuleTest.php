<?php

declare(strict_types=1);

namespace App\Tests\Unit\Game\Advisory;

use App\Game\AdvisoryLevel;
use App\Game\Advisory\TaxStockRule;
use App\Game\Service\StockCalculator;
use App\Game\Service\TaxCalculator;
use App\Tests\Support\Fixture\PlayerBuilder;
use App\Tests\Support\GameConfig;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class TaxStockRuleTest extends TestCase
{
    #[Test]
    public function aShortfallIsStatedAsAnAmountToRecover(): void
    {
        $player = PlayerBuilder::named('Bob')->build();
        $player->cities = 8;
        $player->census = 30;
        $player->treasury = 15;

        $advisory = $this->rule()->evaluate($player);

        $this->assertSame('You must recover 6 stock to pay your taxes', $advisory->message);
        $this->assertSame(AdvisoryLevel::Caution, $advisory->level);
    }

    #[Test]
    public function acoveredBillIsStatedAsReassurance(): void
    {
        $player = PlayerBuilder::named('Bob')->build();
        $player->cities = 3;
        $player->census = 10;
        $player->treasury = 10;

        $this->assertSame('Your stock covers your taxes', $this->rule()->evaluate($player)->message);
    }

    /** Immunity is stated, not merely reflected by the absence of a warning. */
    #[Test]
    public function immunityIsStatedEvenOnARealShortfall(): void
    {
        $player = PlayerBuilder::named('Bob')->build();
        $player->cities = 8;
        $player->census = 30;
        $player->treasury = 15;
        $player->ownAdvances(['democracy']);

        $this->assertSame('Your cities never revolt over taxes', $this->rule()->evaluate($player)->message);
    }

    private function rule(): TaxStockRule
    {
        return new TaxStockRule($this->tax());
    }

    private function tax(): TaxCalculator
    {
        return new TaxCalculator(new StockCalculator(GameConfig::gameData()), GameConfig::advanceEffects());
    }
}
