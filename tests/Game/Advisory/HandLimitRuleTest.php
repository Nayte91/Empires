<?php

declare(strict_types=1);

namespace App\Tests\Game\Advisory;

use App\Entity\GameSession;
use App\Entity\Player;
use App\Game\Advisory\HandLimitRule;
use App\Game\Dto\Advisory;
use App\Game\Service\HandSizeCalculator;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The rule itself only decides "over the limit → Danger advisory, otherwise silence". Which number
 * the limit happens to be, per player count and per advance owned, belongs to
 * {@see HandSizeCalculator} and is pinned by its own test — restating that bracket table here made
 * a change to it break two files instead of one.
 */
final class HandLimitRuleTest extends TestCase
{
    #[Test]
    public function aHandExactlyAtTheLimitYieldsNoAdvisory(): void
    {
        $player = new Player(new GameSession(), 'Bob');
        $player->cards = 8;

        $this->assertNotInstanceOf(Advisory::class, new HandLimitRule(new HandSizeCalculator())->evaluate($player));
    }

    #[Test]
    public function aHandOverTheLimitGetsTheMustDiscardAdvisory(): void
    {
        $player = new Player(new GameSession(), 'Bob');
        $player->cards = 9;

        $advisory = new HandLimitRule(new HandSizeCalculator())->evaluate($player);

        $this->assertInstanceOf(Advisory::class, $advisory);
        $this->assertSame('You must discard a card!', $advisory->message);
    }
}
