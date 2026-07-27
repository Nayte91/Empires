<?php

declare(strict_types=1);

namespace App\Tests\Game\Service;

use App\Entity\GameSession;
use App\Entity\Player;
use App\Game\Service\CitySupportCalculator;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class CitySupportCalculatorTest extends TestCase
{
    #[Test]
    public function everyCityDemandsTwoPopulation(): void
    {
        $player = $this->createPlayer();
        $player->cities = 7;

        $this->assertSame(14, new CitySupportCalculator()->required($player));
    }

    #[Test]
    public function spareCensusIsWhatSitsAboveTheDemand(): void
    {
        $player = $this->createPlayer();
        $player->cities = 7;
        $player->census = 20;

        $this->assertSame(6, new CitySupportCalculator()->spareCensus($player));
        $this->assertFalse(new CitySupportCalculator()->citiesAreUnsupported($player));
    }

    #[Test]
    public function anUnderSupportedPlayerHasNoSpareCensusLeft(): void
    {
        $player = $this->createPlayer();
        $player->cities = 7;
        $player->census = 10;

        $this->assertSame(0, new CitySupportCalculator()->spareCensus($player));
        $this->assertTrue(new CitySupportCalculator()->citiesAreUnsupported($player));
    }

    /** Meeting the demand exactly still supports the cities — the margin is simply zero. */
    #[Test]
    public function meetingTheDemandExactlySupportsTheCities(): void
    {
        $player = $this->createPlayer();
        $player->cities = 5;
        $player->census = 10;

        $this->assertSame(0, new CitySupportCalculator()->spareCensus($player));
        $this->assertFalse(new CitySupportCalculator()->citiesAreUnsupported($player));
    }

    private function createPlayer(): Player
    {
        return new Player(new GameSession(), 'Bob');
    }
}
