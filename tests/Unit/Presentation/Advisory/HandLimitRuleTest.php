<?php

declare(strict_types=1);

namespace App\Tests\Unit\Presentation\Advisory;

use App\Presentation\Advisory\HandLimitRule;
use App\Presentation\Advisory\Advisory;
use App\Rules\HandSizeCalculator;
use App\Tests\Support\GameConfig;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use App\Tests\Support\Fixture\PlayerBuilder;

final class HandLimitRuleTest extends TestCase
{
    #[Test]
    public function aHandExactlyAtTheLimitYieldsNoAdvisory(): void
    {
        $player = PlayerBuilder::named('Bob')->withCards(8)->build();

        $this->assertNotInstanceOf(Advisory::class, new HandLimitRule($this->hand())->evaluate($player));
    }

    #[Test]
    public function aHandOneCardOverTheLimitAsksForOneCardInTheSingular(): void
    {
        $player = PlayerBuilder::named('Bob')->withCards(9)->build();

        $advisory = new HandLimitRule($this->hand())->evaluate($player);

        $this->assertInstanceOf(Advisory::class, $advisory);
        $this->assertSame('You must discard 1 card!', $advisory->message);
    }

    /** Every other test on this rule sits at an excess of exactly one, the single value where a constant message is right. */
    #[Test]
    public function aHandSeveralCardsOverTheLimitAsksForThatManyCards(): void
    {
        $player = PlayerBuilder::named('Bob')->withCards(11)->build();

        $advisory = new HandLimitRule($this->hand())->evaluate($player);

        $this->assertInstanceOf(Advisory::class, $advisory);
        $this->assertSame('You must discard 3 cards!', $advisory->message);
    }

    private function hand(): HandSizeCalculator
    {
        return new HandSizeCalculator(GameConfig::gameRegistry(), GameConfig::advanceEffects());
    }
}
