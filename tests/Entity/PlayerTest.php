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

        self::assertSame(0, $player->cities);
        self::assertSame(1, $player->census);
        self::assertSame(0, $player->treasury);
    }

    #[Test]
    public function citiesClampsToNineAndZero(): void
    {
        $player = new Player(new GameSession(), 'Bob');

        $player->cities = 10;
        self::assertSame(9, $player->cities);

        $player->cities = -1;
        self::assertSame(0, $player->cities);
    }

    #[Test]
    public function censusClampsToFiftyFiveAndZero(): void
    {
        $player = new Player(new GameSession(), 'Bob');

        $player->census = 56;
        self::assertSame(55, $player->census);

        $player->census = -1;
        self::assertSame(0, $player->census);
    }

    #[Test]
    public function treasuryClampsToFiftyFiveAndZero(): void
    {
        $player = new Player(new GameSession(), 'Bob');

        $player->treasury = 56;
        self::assertSame(55, $player->treasury);

        $player->treasury = -1;
        self::assertSame(0, $player->treasury);
    }
}
