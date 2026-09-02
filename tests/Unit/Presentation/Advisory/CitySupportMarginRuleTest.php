<?php

declare(strict_types=1);

namespace App\Tests\Unit\Presentation\Advisory;

use App\Presentation\Advisory\CitySupportMarginRule;
use App\Presentation\Advisory\AdvisoryLevel;
use App\Presentation\Advisory\Advisory;
use App\Rules\CitySupportCalculator;
use App\Tests\Support\Fixture\PlayerBuilder;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class CitySupportMarginRuleTest extends TestCase
{
    #[Test]
    public function theMarginIsStatedAsPopulationHeldOverTheCityCount(): void
    {
        $player = PlayerBuilder::named('Bob')->withCities(7)->withCensus(20)->build();

        $advisory = $this->rule()->evaluate($player);

        $this->assertInstanceOf(Advisory::class, $advisory);
        $this->assertSame('You are 6 population over your city count', $advisory->message);
        $this->assertSame(AdvisoryLevel::Neutral, $advisory->level);
    }

    #[Test]
    public function sittingExactlyOnTheThresholdIsWordedWithoutAFigure(): void
    {
        $player = PlayerBuilder::named('Bob')->withCities(5)->withCensus(10)->build();

        $advisory = $this->rule()->evaluate($player);

        $this->assertInstanceOf(Advisory::class, $advisory);
        $this->assertSame('You cannot afford to lose any population', $advisory->message);
    }

    #[Test]
    public function anUnderSupportedPlayerGetsNoMarginLine(): void
    {
        $player = PlayerBuilder::named('Bob')->withCities(5)->withCensus(4)->build();

        $this->assertNotInstanceOf(\App\Presentation\Advisory\Advisory::class, $this->rule()->evaluate($player));
    }

    private function rule(): CitySupportMarginRule
    {
        return new CitySupportMarginRule(new CitySupportCalculator());
    }
}
