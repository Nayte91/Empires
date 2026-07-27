<?php

declare(strict_types=1);

namespace App\Tests\Game\Service;

use App\Entity\GameSession;
use App\Entity\Player;
use App\Game\Service\HandSizeCalculator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class HandSizeCalculatorTest extends TestCase
{
    #[Test]
    #[DataProvider('provideBaseLimitFollowsThePlayerCountCases')]
    public function baseLimitFollowsThePlayerCount(int $playerCount, int $expectedLimit): void
    {
        $this->assertSame($expectedLimit, new HandSizeCalculator()->baseLimitFor($playerCount));
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
    public function roadBuildingRaisesItsOwnersLimitByOne(): void
    {
        $player = $this->createPlayer(9);

        $calculator = new HandSizeCalculator();

        $this->assertSame(8, $calculator->limitFor($player));

        $player->ownAdvances([HandSizeCalculator::EXTRA_CARD_ADVANCE]);

        $this->assertSame(9, $calculator->limitFor($player));
    }

    /** The advance stacks on the table's own bracket rather than replacing it. */
    #[Test]
    public function theAdvanceStacksOnTheLargeGameBracket(): void
    {
        $player = $this->createPlayer(12);
        $player->ownAdvances([HandSizeCalculator::EXTRA_CARD_ADVANCE]);

        $this->assertSame(10, new HandSizeCalculator()->limitFor($player));
    }

    #[Test]
    public function theExtraCardIsTheDifferenceBetweenBreakingTheLimitAndNot(): void
    {
        $player = $this->createPlayer(9);
        $player->cards = 9;

        $calculator = new HandSizeCalculator();

        $this->assertTrue($calculator->isOverLimit($player));

        $player->ownAdvances([HandSizeCalculator::EXTRA_CARD_ADVANCE]);

        $this->assertFalse($calculator->isOverLimit($player));
    }

    private function createPlayer(int $playerCount): Player
    {
        $game = new GameSession();
        $game->playerCount = $playerCount;

        return new Player($game, 'Bob');
    }
}
