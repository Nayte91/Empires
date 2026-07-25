<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\GameSession;
use App\Entity\Player;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class PlayerTest extends TestCase
{
    #[Test]
    public function citiesAndCensusDefaultToZeroAndOne(): void
    {
        $player = new Player(new GameSession(), 'Bob');

        $this->assertSame(0, $player->cities);
        $this->assertSame(1, $player->census);
        $this->assertSame(0, $player->treasury);
    }

    #[Test]
    public function citiesClampsToNineAndZero(): void
    {
        $player = new Player(new GameSession(), 'Bob');

        $player->cities = 10;
        $this->assertSame(9, $player->cities);

        $player->cities = -1;
        $this->assertSame(0, $player->cities);
    }

    #[Test]
    public function censusClampsToFiftyFiveAndZero(): void
    {
        $player = new Player(new GameSession(), 'Bob');

        $player->census = 56;
        $this->assertSame(55, $player->census);

        $player->census = -1;
        $this->assertSame(0, $player->census);
    }

    #[Test]
    public function treasuryClampsToFiftyFiveAndZero(): void
    {
        $player = new Player(new GameSession(), 'Bob');

        $player->treasury = 56;
        $this->assertSame(55, $player->treasury);

        $player->treasury = -1;
        $this->assertSame(0, $player->treasury);
    }

    #[Test]
    public function shipsClampsToFourAndZero(): void
    {
        $player = new Player(new GameSession(), 'Bob');

        $player->ships = 5;
        $this->assertSame(4, $player->ships);

        $player->ships = -1;
        $this->assertSame(0, $player->ships);
    }

    #[Test]
    public function cardsClampsToZero(): void
    {
        $player = new Player(new GameSession(), 'Bob');

        $player->cards = -3;
        $this->assertSame(0, $player->cards);
    }
}
