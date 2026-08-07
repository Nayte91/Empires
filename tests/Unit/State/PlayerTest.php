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

    /**
     * A player has one limit, and the slug is a derived writing of the name rather than a second
     * field with a bound of its own. Honouring it takes work, because transliteration expands: a
     * name of thirty CJK characters is inside the bound yet slugifies to 119 of pinyin, a factor of
     * about four. The name hook is the only place a slug is ever produced, so it is the only place
     * that can hold the single limit for both.
     */
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
        yield 'thirty CJK characters slugify to 119 and are cut back to 30' => [str_repeat('漢', 30), str_repeat('han-', 7).'ha'];

        // Unreachable through the form, which caps the name at the same 30: with a single limit,
        // only a transliterating script can overflow it. Kept as the guard's own N+1 boundary,
        // free of any transliteration table.
        yield 'one character past the limit loses exactly that character' => [str_repeat('a', 31), str_repeat('a', 30)];
    }

    /**
     * The positive control for the truncation above: the guard must cost nothing to a name that
     * fits. The accented row is the one worth having — thirty "é" sit exactly on the limit and come
     * back out at thirty, so transliterating an accented name does not quietly spend characters the
     * player was told they could use.
     */
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

        yield 'thirty accented characters weigh sixty bytes and pass through whole' => [str_repeat('é', 30), str_repeat('e', 30)];

        yield 'a slug landing exactly on the limit is untouched' => [str_repeat('a', 30), str_repeat('a', 30)];
    }

    /**
     * A truncated slug must still be a whole string: well-formed, and never ending on half a
     * character. This does not pin mb_substr() over substr() and must not be read as doing so —
     * the cut runs after AsciiSlugger has already reduced the name to pure ASCII, where the two are
     * indistinguishable. It pins the property the column and the board URL depend on, whichever
     * function makes the cut.
     */
    #[Test]
    public function theTruncatedSlugNeverEndsOnABrokenCharacter(): void
    {
        $player = new Player(new Game(), str_repeat('漢', 30), 'minoa');

        $this->assertTrue(mb_check_encoding($player->slug, 'UTF-8'));
        $this->assertSame(mb_strlen($player->slug), strlen($player->slug));
    }
}
