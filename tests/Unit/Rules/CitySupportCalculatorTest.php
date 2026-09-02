<?php

declare(strict_types=1);

namespace App\Tests\Unit\Rules;

use App\Rules\CitySupportCalculator;
use App\Tests\Support\Fixture\PlayerBuilder;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class CitySupportCalculatorTest extends TestCase
{
    #[Test]
    public function everyCityDemandsTwoPopulation(): void
    {
        $player = PlayerBuilder::named('Bob')->withCities(7)->build();

        $this->assertSame(14, new CitySupportCalculator()->required($player));
    }

    #[Test]
    public function aPlayerHoldingMoreThanTheDemandSupportsTheirCities(): void
    {
        $player = PlayerBuilder::named('Bob')->withCities(7)->withCensus(20)->build();

        $this->assertFalse(new CitySupportCalculator()->citiesAreUnsupported($player));
    }

    #[Test]
    public function aPlayerHoldingLessThanTheDemandLeavesTheCitiesUnsupported(): void
    {
        $player = PlayerBuilder::named('Bob')->withCities(7)->withCensus(10)->build();

        $this->assertTrue(new CitySupportCalculator()->citiesAreUnsupported($player));
    }

    #[Test]
    public function meetingTheDemandExactlySupportsTheCities(): void
    {
        $player = PlayerBuilder::named('Bob')->withCities(5)->withCensus(10)->build();

        $this->assertFalse(new CitySupportCalculator()->citiesAreUnsupported($player));
    }
}
