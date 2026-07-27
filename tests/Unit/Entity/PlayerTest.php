<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\GameSession;
use App\Entity\Player;
use App\Game\Shop\CreditEntry;
use App\Game\Shop\CreditSource;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class PlayerTest extends TestCase
{
    #[Test]
    public function citiesAndCensusDefaultToZeroAndOne(): void
    {
        $player = new Player(new GameSession(), 'Bob');

        $this->assertSame(0, $player->cities);
        $this->assertSame(1, $player->census);
        $this->assertSame(0, $player->treasury);
    }

    #[Test]
    #[DataProvider('provideEachStatClampsToItsOwnBoundsCases')]
    public function eachStatClampsToItsOwnBounds(string $stat, int $assigned, int $expected): void
    {
        $player = new Player(new GameSession(), 'Bob');

        $player->{$stat} = $assigned;

        $this->assertSame($expected, $player->{$stat});
    }

    /** @return iterable<string, array{string, int, int}> */
    public static function provideEachStatClampsToItsOwnBoundsCases(): iterable
    {
        yield 'cities above the nine-city ceiling' => ['cities', 10, 9];

        yield 'cities below zero' => ['cities', -1, 0];

        yield 'census above the fifty-five ceiling' => ['census', 56, 55];

        yield 'census below zero' => ['census', -1, 0];

        yield 'treasury above the fifty-five ceiling' => ['treasury', 56, 55];

        yield 'treasury below zero' => ['treasury', -1, 0];

        yield 'ships above the four-ship fleet cap' => ['ships', 5, 4];

        yield 'ships below zero' => ['ships', -1, 0];

        yield 'cards below zero, the only stat with no ceiling' => ['cards', -3, 0];
    }

    #[Test]
    public function revokeCreditsRemovesOnlyTheEntriesReasonedAccordingly(): void
    {
        $player = new Player(new GameSession(), 'Bob');
        $player->postCredit(new CreditEntry(1, 'craft', 10, CreditSource::Shop, 'advance:pottery'));
        $player->postCredit(new CreditEntry(1, 'art', 5, CreditSource::Shop, 'advance:pottery'));
        $player->postCredit(new CreditEntry(2, 'craft', 5, CreditSource::Shop, 'other-reason'));

        $player->revokeCredits('advance:pottery');

        $this->assertEquals(
            [new CreditEntry(2, 'craft', 5, CreditSource::Shop, 'other-reason')],
            $player->creditLedger,
        );
    }

    #[Test]
    public function revokeCreditsReindexesTheRemainingEntriesAsASequentialList(): void
    {
        $player = new Player(new GameSession(), 'Bob');
        $player->postCredit(new CreditEntry(1, 'craft', 10, CreditSource::Shop, 'advance:pottery'));
        $player->postCredit(new CreditEntry(2, 'craft', 5, CreditSource::Shop, 'other-reason'));
        $player->postCredit(new CreditEntry(3, 'craft', 5, CreditSource::Shop, 'advance:pottery'));

        $player->revokeCredits('advance:pottery');

        $this->assertTrue(array_is_list($player->creditLedger));
    }
}
