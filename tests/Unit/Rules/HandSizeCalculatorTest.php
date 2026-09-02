<?php

declare(strict_types=1);

namespace App\Tests\Unit\Rules;

use App\Rules\HandSizeCalculator;
use App\Tests\Support\Fixture\GameBuilder;
use App\Tests\Support\Fixture\PlayerBuilder;
use App\Tests\Support\GameConfig;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class HandSizeCalculatorTest extends TestCase
{
    #[Test]
    #[DataProvider('provideBaseLimitFollowsThePlayerCountCases')]
    public function baseLimitFollowsThePlayerCount(int $playerCount, int $expectedLimit): void
    {
        $this->assertSame($expectedLimit, $this->hand()->baseLimitFor($playerCount));
    }

    /** @return iterable<string, array{int, int}> */
    public static function provideBaseLimitFollowsThePlayerCountCases(): iterable
    {
        yield 'standard game' => [9, 8];

        yield 'just under large-game threshold' => [11, 8];

        yield 'large-game threshold' => [12, 9];

        yield 'large-game upper player count' => [18, 9];
    }

    #[Test]
    #[DataProvider('provideLimitForStacksTheExtraCardAdvanceOnTheTableBracketCases')]
    public function limitForStacksTheExtraCardAdvanceOnTheTableBracket(int $playerCount, bool $ownsTheAdvance, int $expectedLimit): void
    {
        $builder = PlayerBuilder::named('Bob')->in(GameBuilder::create()->withPlayerCount($playerCount)->build());

        if ($ownsTheAdvance) {
            $builder = $builder->withAdvances(['roadbuilding']);
        }

        $player = $builder->build();

        $this->assertSame($expectedLimit, $this->hand()->limitFor($player));
    }

    /** @return iterable<string, array{int, bool, int}> */
    public static function provideLimitForStacksTheExtraCardAdvanceOnTheTableBracketCases(): iterable
    {
        yield 'standard game without the advance' => [9, false, 8];

        yield 'standard game with the advance' => [9, true, 9];

        yield 'large game with the advance stacks on the raised bracket' => [12, true, 10];
    }

    #[Test]
    #[DataProvider('provideExcessIsWhatTheHandHoldsBeyondItsLimitCases')]
    public function excessIsWhatTheHandHoldsBeyondItsLimit(int $cards, int $expectedExcess): void
    {
        $player = PlayerBuilder::named('Bob')->in(GameBuilder::create()->withPlayerCount(12)->build())->withCards($cards)->build();

        $this->assertSame($expectedExcess, $this->hand()->excessFor($player));
    }

    /** @return iterable<string, array{int, int}> */
    public static function provideExcessIsWhatTheHandHoldsBeyondItsLimitCases(): iterable
    {
        yield 'a hand below the limit owes nothing' => [5, 0];

        yield 'a hand exactly at the limit owes nothing, never a negative' => [9, 0];

        yield 'one card over owes one' => [10, 1];

        yield 'the reported case: eleven cards against a limit of nine owes two' => [11, 2];
    }

    #[Test]
    public function theExtraCardIsTheDifferenceBetweenBreakingTheLimitAndNot(): void
    {
        $player = PlayerBuilder::named('Bob')->in(GameBuilder::create()->withPlayerCount(9)->build())->withCards(9)->build();

        $calculator = $this->hand();

        $this->assertTrue($calculator->isOverLimit($player));

        $player->ownAdvances(['roadbuilding']);

        $this->assertFalse($calculator->isOverLimit($player));
    }

    private function hand(): HandSizeCalculator
    {
        return new HandSizeCalculator(GameConfig::gameRegistry(), GameConfig::advanceEffects());
    }
}
