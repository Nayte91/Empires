<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\GameSession;
use App\Entity\Player;
use App\Game\Shop\CreditEntry;
use App\Game\Shop\CreditSource;
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
    public function citiesClampsToNineAndZero(): void
    {
        $player = new Player(new GameSession(), 'Bob');

        $player->cities = 10;
        $this->assertSame(9, $player->cities);

        $player->cities = -1;
        $this->assertSame(0, $player->cities);
    }

    #[Test]
    public function censusClampsToFiftyFiveAndZero(): void
    {
        $player = new Player(new GameSession(), 'Bob');

        $player->census = 56;
        $this->assertSame(55, $player->census);

        $player->census = -1;
        $this->assertSame(0, $player->census);
    }

    #[Test]
    public function treasuryClampsToFiftyFiveAndZero(): void
    {
        $player = new Player(new GameSession(), 'Bob');

        $player->treasury = 56;
        $this->assertSame(55, $player->treasury);

        $player->treasury = -1;
        $this->assertSame(0, $player->treasury);
    }

    #[Test]
    public function shipsClampsToFourAndZero(): void
    {
        $player = new Player(new GameSession(), 'Bob');

        $player->ships = 5;
        $this->assertSame(4, $player->ships);

        $player->ships = -1;
        $this->assertSame(0, $player->ships);
    }

    #[Test]
    public function cardsClampsToZero(): void
    {
        $player = new Player(new GameSession(), 'Bob');

        $player->cards = -3;
        $this->assertSame(0, $player->cards);
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
