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

    /** The advance stacks on the table's own bracket rather than replacing it. */
    #[Test]
    #[DataProvider('provideLimitForStacksTheExtraCardAdvanceOnTheTableBracketCases')]
    public function limitForStacksTheExtraCardAdvanceOnTheTableBracket(int $playerCount, bool $ownsTheAdvance, int $expectedLimit): void
    {
        $player = PlayerBuilder::named('Bob')->in(GameBuilder::create()->withPlayerCount($playerCount)->build())->build();

        if ($ownsTheAdvance) {
            $player->ownAdvances(['roadbuilding']);
        }

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
    public function theExtraCardIsTheDifferenceBetweenBreakingTheLimitAndNot(): void
    {
        $player = PlayerBuilder::named('Bob')->in(GameBuilder::create()->withPlayerCount(9)->build())->build();
        $player->cards = 9;

        $calculator = $this->hand();

        $this->assertTrue($calculator->isOverLimit($player));

        $player->ownAdvances(['roadbuilding']);

        $this->assertFalse($calculator->isOverLimit($player));
    }

    private function hand(): HandSizeCalculator
    {
        return new HandSizeCalculator(GameConfig::gameData(), GameConfig::advanceEffects());
    }
}
