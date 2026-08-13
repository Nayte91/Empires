<?php

declare(strict_types=1);

namespace App\Tests\Unit\Presentation\Advisory;

use App\State\Game;
use App\State\Player;
use App\Presentation\Advisory\HandLimitRule;
use App\Presentation\Advisory\Advisory;
use App\Rules\HandSizeCalculator;
use App\Tests\Support\GameConfig;
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
        $player = new Player(new Game(), 'Bob', 'minoa');
        $player->cards = 8;

        $this->assertNotInstanceOf(Advisory::class, new HandLimitRule($this->hand())->evaluate($player));
    }

    #[Test]
    public function aHandOneCardOverTheLimitAsksForOneCardInTheSingular(): void
    {
        $player = new Player(new Game(), 'Bob', 'minoa');
        $player->cards = 9;

        $advisory = new HandLimitRule($this->hand())->evaluate($player);

        $this->assertInstanceOf(Advisory::class, $advisory);
        $this->assertSame('You must discard 1 card!', $advisory->message);
    }

    /**
     * The bug this guards: the message used to be a constant, so a hand three cards over still read
     * "discard a card" and a player following it stopped two cards early. Every test on this rule
     * happened to sit at an excess of exactly one, the single value where that constant was right.
     */
    #[Test]
    public function aHandSeveralCardsOverTheLimitAsksForThatManyCards(): void
    {
        $player = new Player(new Game(), 'Bob', 'minoa');
        $player->cards = 11;

        $advisory = new HandLimitRule($this->hand())->evaluate($player);

        $this->assertInstanceOf(Advisory::class, $advisory);
        $this->assertSame('You must discard 3 cards!', $advisory->message);
    }

    private function hand(): HandSizeCalculator
    {
        return new HandSizeCalculator(GameConfig::gameRegistry(), GameConfig::advanceEffects());
    }
}
