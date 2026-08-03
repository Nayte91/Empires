<?php

declare(strict_types=1);

namespace App\Tests\Component;

use App\State\Player;
use App\Tests\Support\Fixture\GameBuilder;
use App\Tests\Support\Fixture\PlayerBuilder;
use App\Tests\Support\GameFixtureTrait;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\UX\TwigComponent\Test\InteractsWithTwigComponents;

/**
 * The player's counterpart to GameChronicle. What the finished page drops from the live board is
 * pinned from the outside in PlayerSagaViewTest, where the branch that reaches it lives; what stays
 * here is the retrospective's own content — the counters the board offered as pickers, now read-only,
 * and the advances with what they were worth.
 */
final class PlayerSagaTest extends WebTestCase
{
    use GameFixtureTrait;
    use InteractsWithTwigComponents;

    /**
     * The five stats ControlBoard offers live, in its order, each as a term and its value. The sixth
     * one the board tracks — the A.S.T. position — is deliberately not among them: the shared board
     * rendered just above already carries every marker on the track.
     */
    #[Test]
    public function theFinalCountersAreTheFiveTrackedStatsAndNotTheAstPosition(): void
    {
        $player = $this->createPlayerHoldingCounters();

        $rows = $this->renderTwigComponent('PlayerSaga', ['player' => $player])->crawler()->filter('dl > div[data-stat]');

        $this->assertSame(['cities', 'ships', 'census', 'treasury', 'cards'], $rows->each(static fn (Crawler $row): ?string => $row->attr('data-stat')));
        $this->assertSame(['Cities', 'Ships', 'Population', 'Treasury', 'Cards'], $rows->each(static fn (Crawler $row): string => $row->filter('dt')->text()));
        $this->assertSame(['3', '2', '7', '40', '5'], $rows->each(static fn (Crawler $row): string => $row->filter('dd')->text()));
    }

    /**
     * The heading quotes the advances term of the final score, exactly as the live board's does, so
     * the retrospective cannot drift from what the score itself counted. Pottery is worth 1 and
     * agriculture 3.
     */
    #[Test]
    public function theAdvancesHeadingCarriesWhatThoseAdvancesWereWorth(): void
    {
        $player = PlayerBuilder::named('Alice')
            ->in(GameBuilder::create()->persist($this->entityManager))
            ->withAdvances(['pottery', 'agriculture'])
            ->persist($this->entityManager)
        ;

        $crawler = $this->renderTwigComponent('PlayerSaga', ['player' => $player])->crawler();

        $this->assertSame('Advances (4 Victory Points)', $crawler->filter('section[aria-label="Owned advances"] h2')->text());
        $this->assertCount(2, $crawler->filter('section[aria-label="Owned advances"] img[id^="product-"]'));
    }

    /** An empty grid is indistinguishable from a page that failed to draw one; a sentence is not. */
    #[Test]
    public function aPlayerWhoOwnedNothingIsToldSoRatherThanShownAnEmptyGrid(): void
    {
        $game = GameBuilder::create()->persist($this->entityManager);
        $collector = PlayerBuilder::named('Alice')->in($game)->withAdvances(['pottery'])->persist($this->entityManager);
        $emptyHanded = PlayerBuilder::named('Bob')->in($game)->withEmpire('hellas')->persist($this->entityManager);

        $withAdvances = $this->renderTwigComponent('PlayerSaga', ['player' => $collector])->crawler();
        $withNone = $this->renderTwigComponent('PlayerSaga', ['player' => $emptyHanded])->crawler();

        $this->assertCount(1, $withAdvances->filter('section[aria-label="Owned advances"] img[id^="product-"]'));
        $this->assertCount(0, $withAdvances->filter('section[aria-label="Owned advances"] p'));

        $this->assertCount(0, $withNone->filter('section[aria-label="Owned advances"] img[id^="product-"]'));
        $this->assertSame('No advance owned.', $withNone->filter('section[aria-label="Owned advances"] p')->text());
    }

    /** Five distinct values, so a counter wired to the wrong stat cannot pass by coincidence. */
    private function createPlayerHoldingCounters(): Player
    {
        $player = PlayerBuilder::named('Alice')
            ->in(GameBuilder::create()->persist($this->entityManager))
            ->withCities(3)
            ->persist($this->entityManager)
        ;

        $player->ships = 2;
        $player->census = 7;
        $player->treasury = 40;
        $player->cards = 5;

        return $player;
    }
}
