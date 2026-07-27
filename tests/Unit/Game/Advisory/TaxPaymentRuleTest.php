<?php

declare(strict_types=1);

namespace App\Tests\Unit\Game\Advisory;

use App\Entity\GameSession;
use App\Entity\Player;
use App\Game\Advisory\TaxPaymentRule;
use App\Game\Dto\Advisory;
use App\Game\GameData;
use App\Game\Service\StockCalculator;
use App\Game\Service\TaxCalculator;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class TaxPaymentRuleTest extends TestCase
{
    #[Test]
    public function sufficientStockYieldsNoAdvisory(): void
    {
        $player = new Player(new GameSession(), 'Bob');
        $player->cities = 3;
        $player->census = 1;
        $player->treasury = 0;

        $this->assertNotInstanceOf(\App\Game\Dto\Advisory::class, new TaxPaymentRule($this->tax())->evaluate($player));
    }

    #[Test]
    public function insufficientStockGetsCantPayTaxesAdvisory(): void
    {
        $player = new Player(new GameSession(), 'Bob');
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
        $player = new Player(new GameSession(), 'Bob');
        $player->cities = 3;
        $player->census = 6;
        $player->treasury = 43;

        $this->assertNotInstanceOf(\App\Game\Dto\Advisory::class, new TaxPaymentRule($this->tax())->evaluate($player));
    }

    /** Democracy is outright immunity, so the warning must go even on a genuine shortfall. */
    #[Test]
    public function anImmunePlayerIsNeverWarnedDespiteAShortfall(): void
    {
        $player = new Player(new GameSession(), 'Bob');
        $player->cities = 3;
        $player->census = 30;
        $player->treasury = 30;
        $player->ownAdvances([TaxCalculator::IMMUNITY_ADVANCE]);

        $this->assertNotInstanceOf(Advisory::class, new TaxPaymentRule($this->tax())->evaluate($player));
    }

    private function tax(): TaxCalculator
    {
        return new TaxCalculator(new StockCalculator(
            new GameData(\dirname(__DIR__, 4).'/config/game/game_data.yaml'),
        ));
    }
}
