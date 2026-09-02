<?php

declare(strict_types=1);

namespace App\Tests\Unit\Presentation\Advisory;

use App\Presentation\Advisory\CitySupportRule;
use App\Presentation\Advisory\HandLimitRule;
use App\Presentation\Advisory\TaxPaymentRule;
use App\Rules\CitySupportCalculator;
use App\Rules\HandSizeCalculator;
use App\Presentation\Advisory\PlayerAdvisor;
use App\Rules\StockCalculator;
use App\Rules\TaxCalculator;
use App\Tests\Support\GameConfig;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use App\Tests\Support\Fixture\PlayerBuilder;

final class PlayerAdvisorTest extends TestCase
{
    #[Test]
    public function wellSupportedPlayerGetsNoAdvisories(): void
    {
        $player = PlayerBuilder::named('Bob')->withCities(2)->withCensus(10)->build();

        $advisor = new PlayerAdvisor([new CitySupportRule(new CitySupportCalculator()), new TaxPaymentRule($this->tax()), new HandLimitRule($this->hand())]);

        $this->assertSame([], $advisor->advisoriesFor($player));
    }

    #[Test]
    public function troubledPlayerGetsAllAdvisoriesInPriorityOrder(): void
    {
        $player = PlayerBuilder::named('Bob')->withCities(5)->withCensus(1)->withTreasury(50)->withCards(9)->build();

        $advisor = new PlayerAdvisor([new CitySupportRule(new CitySupportCalculator()), new TaxPaymentRule($this->tax()), new HandLimitRule($this->hand())]);
        $advisories = $advisor->advisoriesFor($player);

        $this->assertCount(3, $advisories);
        $this->assertSame("You can't support your cities!", $advisories[0]->message);
        $this->assertSame("You can't pay your taxes!", $advisories[1]->message);
        $this->assertSame('You must discard 1 card!', $advisories[2]->message);
    }

    #[Test]
    public function playerTriggeringOnlyHandLimitGetsSingleAdvisory(): void
    {
        $player = PlayerBuilder::named('Bob')->withCities(2)->withCensus(10)->withCards(9)->build();

        $advisor = new PlayerAdvisor([new CitySupportRule(new CitySupportCalculator()), new TaxPaymentRule($this->tax()), new HandLimitRule($this->hand())]);
        $advisories = $advisor->advisoriesFor($player);

        $this->assertCount(1, $advisories);
        $this->assertSame('You must discard 1 card!', $advisories[0]->message);
    }

    private function tax(): TaxCalculator
    {
        return new TaxCalculator(new StockCalculator(GameConfig::gameRegistry()), GameConfig::advanceEffects());
    }

    private function hand(): HandSizeCalculator
    {
        return new HandSizeCalculator(GameConfig::gameRegistry(), GameConfig::advanceEffects());
    }
}
