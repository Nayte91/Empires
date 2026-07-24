<?php

declare(strict_types=1);

namespace App\Tests\Game\Advisory;

use App\Entity\GameSession;
use App\Entity\Player;
use App\Game\Advisory\HandLimitRule;
use App\Game\Dto\Advisory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class HandLimitRuleTest extends TestCase
{
    #[Test]
    #[DataProvider('provideHandWithinLimitYieldsNoAdvisoryCases')]
    public function handWithinLimitYieldsNoAdvisory(int $playerCount, int $cards): void
    {
        $game = new GameSession();
        $game->playerCount = $playerCount;
        $player = new Player($game, 'Bob');
        $player->cards = $cards;

        $this->assertNotInstanceOf(Advisory::class, new HandLimitRule()->evaluate($player));
    }

    /** @return iterable<string, array{int, int}> */
    public static function provideHandWithinLimitYieldsNoAdvisoryCases(): iterable
    {
        yield 'exactly at the hand limit' => [9, 8];

        yield 'fresh player with default stats' => [9, 0];

        yield 'large game boundary stays within raised limit' => [12, 9];

        yield 'large game upper player count stays within raised limit' => [18, 9];
    }

    #[Test]
    #[DataProvider('provideOverHandLimitGetsMustDiscardAdvisoryCases')]
    public function overHandLimitGetsMustDiscardAdvisory(int $playerCount, int $cards): void
    {
        $game = new GameSession();
        $game->playerCount = $playerCount;
        $player = new Player($game, 'Bob');
        $player->cards = $cards;

        $advisory = new HandLimitRule()->evaluate($player);

        $this->assertInstanceOf(Advisory::class, $advisory);
        $this->assertSame('You must discard a card!', $advisory->message);
    }

    /** @return iterable<string, array{int, int}> */
    public static function provideOverHandLimitGetsMustDiscardAdvisoryCases(): iterable
    {
        yield 'standard game exceeds base limit' => [9, 9];

        yield 'large game boundary exceeds raised limit' => [12, 10];

        yield 'just under large-game threshold keeps base limit' => [11, 9];
    }
}
