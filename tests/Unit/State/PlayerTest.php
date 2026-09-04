<?php

declare(strict_types=1);

namespace App\Tests\Unit\State;

use App\State\Game;
use App\State\Player;
use App\State\CreditEntry;
use App\State\CreditSource;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class PlayerTest extends TestCase
{
    #[Test]
    public function citiesAndCensusDefaultToZeroAndOne(): void
    {
        $player = new Player(new Game(), 'Bob', 'minoa');

        $this->assertSame(0, $player->cities);
        $this->assertSame(1, $player->census);
        $this->assertSame(0, $player->treasury);
    }

    #[Test]
    public function revokeCreditsRemovesOnlyTheEntriesReasonedAccordingly(): void
    {
        $player = new Player(new Game(), 'Bob', 'minoa');
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
        $player = new Player(new Game(), 'Bob', 'minoa');
        $player->postCredit(new CreditEntry(1, 'craft', 10, CreditSource::Shop, 'advance:pottery'));
        $player->postCredit(new CreditEntry(2, 'craft', 5, CreditSource::Shop, 'other-reason'));
        $player->postCredit(new CreditEntry(3, 'craft', 5, CreditSource::Shop, 'advance:pottery'));

        $player->revokeCredits('advance:pottery');

        $this->assertTrue(array_is_list($player->creditLedger));
    }

    #[Test]
    #[DataProvider('provideANameWhoseSlugOverflowsTheLimitIsTruncatedToExactlyItCases')]
    public function aNameWhoseSlugOverflowsTheLimitIsTruncatedToExactlyIt(string $name, string $expectedSlug): void
    {
        $player = new Player(new Game(), $name, 'minoa');

        $this->assertSame($expectedSlug, $player->slug);
        $this->assertSame(Player::MAX_NAME_LENGTH, mb_strlen($player->slug));
    }

    public static function provideANameWhoseSlugOverflowsTheLimitIsTruncatedToExactlyItCases(): iterable
    {
        yield 'twenty CJK characters slugify to 79 and are cut back to 20' => [str_repeat('漢', Player::MAX_NAME_LENGTH), str_repeat('han-', 5)];
        yield 'one character past the limit loses exactly that character' => [str_repeat('a', Player::MAX_NAME_LENGTH + 1), str_repeat('a', Player::MAX_NAME_LENGTH)];
    }

    #[Test]
    #[DataProvider('provideANameWhoseSlugFitsTheLimitIsSluggedWholeCases')]
    public function aNameWhoseSlugFitsTheLimitIsSluggedWhole(string $name, string $expectedSlug): void
    {
        $player = new Player(new Game(), $name, 'minoa');

        $this->assertSame($expectedSlug, $player->slug);
    }

    public static function provideANameWhoseSlugFitsTheLimitIsSluggedWholeCases(): iterable
    {
        yield 'an ordinary name is slugified and nothing else' => ['Peter Parker', 'peter-parker'];

        yield 'twenty accented characters weigh forty bytes and pass through whole' => [str_repeat('é', Player::MAX_NAME_LENGTH), str_repeat('e', Player::MAX_NAME_LENGTH)];

        yield 'a slug landing exactly on the limit is untouched' => [str_repeat('a', Player::MAX_NAME_LENGTH), str_repeat('a', Player::MAX_NAME_LENGTH)];
    }

    #[Test]
    public function theTruncatedSlugNeverEndsOnABrokenCharacter(): void
    {
        $player = new Player(new Game(), str_repeat('漢', 30), 'minoa');

        $this->assertTrue(mb_check_encoding($player->slug, 'UTF-8'));
        $this->assertSame(mb_strlen($player->slug), strlen($player->slug));
    }
}
