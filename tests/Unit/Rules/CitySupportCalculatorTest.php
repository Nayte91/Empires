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
        $player = PlayerBuilder::named('Bob')->build();
        $player->cities = 7;

        $this->assertSame(14, new CitySupportCalculator()->required($player));
    }

    #[Test]
    public function aPlayerHoldingMoreThanTheDemandSupportsTheirCities(): void
    {
        $player = PlayerBuilder::named('Bob')->build();
        $player->cities = 7;
        $player->census = 20;

        $this->assertFalse(new CitySupportCalculator()->citiesAreUnsupported($player));
    }

    #[Test]
    public function aPlayerHoldingLessThanTheDemandLeavesTheCitiesUnsupported(): void
    {
        $player = PlayerBuilder::named('Bob')->build();
        $player->cities = 7;
        $player->census = 10;

        $this->assertTrue(new CitySupportCalculator()->citiesAreUnsupported($player));
    }

    /** The boundary belongs to the player: meeting the demand exactly still supports the cities. */
    #[Test]
    public function meetingTheDemandExactlySupportsTheCities(): void
    {
        $player = PlayerBuilder::named('Bob')->build();
        $player->cities = 5;
        $player->census = 10;

        $this->assertFalse(new CitySupportCalculator()->citiesAreUnsupported($player));
    }
}
