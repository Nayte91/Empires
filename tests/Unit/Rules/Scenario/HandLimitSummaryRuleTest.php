<?php

declare(strict_types=1);

namespace App\Tests\Unit\Rules\Scenario;

use App\Rules\Action\CreateGame;
use App\Rules\Scenario\HandLimitSummaryRule;
use App\Rules\HandSizeCalculator;
use App\Tests\Support\GameConfig;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class HandLimitSummaryRuleTest extends TestCase
{
    #[Test]
    #[DataProvider('provideDescribeReturnsTheCardLimitForPlayerCountCases')]
    public function describeReturnsTheCardLimitForPlayerCount(int $playerCount, string $expectedSummary): void
    {
        $game = new CreateGame();
        $game->playerCount = $playerCount;

        $rule = new HandLimitSummaryRule(new HandSizeCalculator(GameConfig::gameRegistry(), GameConfig::advanceEffects()));

        $this->assertSame($expectedSummary, $rule->describe($game));
    }

    /** @return iterable<string, array{int, string}> */
    public static function provideDescribeReturnsTheCardLimitForPlayerCountCases(): iterable
    {
        yield 'standard game' => [9, 'Card limit: 8'];

        yield 'large-game threshold' => [12, 'Card limit: 9'];
    }
}
