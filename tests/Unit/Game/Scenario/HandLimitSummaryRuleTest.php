<?php

declare(strict_types=1);

namespace App\Tests\Unit\Game\Scenario;

use App\Game\Command\CreateGame;
use App\Game\Scenario\HandLimitSummaryRule;
use App\Game\Service\HandSizeCalculator;
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

        $rule = new HandLimitSummaryRule(new HandSizeCalculator());

        $this->assertSame($expectedSummary, $rule->describe($game));
    }

    /** @return iterable<string, array{int, string}> */
    public static function provideDescribeReturnsTheCardLimitForPlayerCountCases(): iterable
    {
        yield 'standard game' => [9, 'Card limit: 8'];

        yield 'large-game threshold' => [12, 'Card limit: 9'];
    }
}
