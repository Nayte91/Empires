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
        $player = PlayerBuilder::named('Bob')->build();
        $player->cities = 7;
        $player->census = 20;

        $advisory = $this->rule()->evaluate($player);

        $this->assertInstanceOf(Advisory::class, $advisory);
        $this->assertSame('You are 6 population over your city count', $advisory->message);
        $this->assertSame(AdvisoryLevel::Neutral, $advisory->level);
    }

    /** "Up to 0" reads as a mistake, so the boundary gets its own sentence. */
    #[Test]
    public function sittingExactlyOnTheThresholdIsWordedWithoutAFigure(): void
    {
        $player = PlayerBuilder::named('Bob')->build();
        $player->cities = 5;
        $player->census = 10;

        $advisory = $this->rule()->evaluate($player);

        $this->assertInstanceOf(Advisory::class, $advisory);
        $this->assertSame('You cannot afford to lose any population', $advisory->message);
    }

    /** Below the threshold the warning rule speaks instead: there is no margin left to report. */
    #[Test]
    public function anUnderSupportedPlayerGetsNoMarginLine(): void
    {
        $player = PlayerBuilder::named('Bob')->build();
        $player->cities = 5;
        $player->census = 4;

        $this->assertNotInstanceOf(\App\Presentation\Advisory\Advisory::class, $this->rule()->evaluate($player));
    }

    private function rule(): CitySupportMarginRule
    {
        return new CitySupportMarginRule(new CitySupportCalculator());
    }
}
