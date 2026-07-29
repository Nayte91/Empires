<?php

declare(strict_types=1);

namespace App\Tests\Component;

use App\State\Game;
use App\Tests\Support\Fixture\GameBuilder;
use App\Tests\Support\Fixture\PlayerBuilder;
use App\Tests\Support\GameFixtureTrait;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\UX\LiveComponent\Test\InteractsWithLiveComponents;

final class BandTimelineTest extends WebTestCase
{
    use GameFixtureTrait;
    use InteractsWithLiveComponents;

    /** One grid, one column per position: sixteen in basic, seventeen in expert. */
    #[Test]
    public function theBoardStatesEveryPositionOnTheTrack(): void
    {
        $game = GameBuilder::create()->persist($this->entityManager);
        PlayerBuilder::named('Alice')->in($game)->withEmpire('minoa')->persist($this->entityManager);
        PlayerBuilder::named('Bob')->in($game)->withEmpire('assyria')->persist($this->entityManager);

        $crawler = $this->render($game);

        $this->assertCount(1, $crawler->filter('tbody'));
        $this->assertCount(2, $crawler->filter('tbody tr'));
        $this->assertCount(16, $crawler->filter('tbody tr')->eq(0)->filter('td'));
        $this->assertSame(
            ['1', '4', '3', '3', '3', '1', '1'],
            $crawler->filter('thead th[colspan]')->each(static fn (Crawler $header): ?string => $header->attr('colspan')),
        );
    }

    /**
     * The columns line up for everyone, but the ages inside them do not: a slow starter turns over
     * on column 6 where a standard empire turned over on 5. Each row states its own milestones.
     */
    #[Test]
    public function eachCivilizationTurnsItsAgesOverOnItsOwnColumns(): void
    {
        $game = GameBuilder::create()->persist($this->entityManager);
        PlayerBuilder::named('Alice')->in($game)->withEmpire('minoa')->persist($this->entityManager);
        PlayerBuilder::named('Bob')->in($game)->withEmpire('assyria')->persist($this->entityManager);

        $rows = $this->render($game)->filter('tbody tr');

        $this->assertSame([1, 6, 9, 11, 14, 15], $this->ageStartsOf($rows->eq(0)));
        $this->assertSame([1, 5, 8, 11, 14, 15], $this->ageStartsOf($rows->eq(1)));
    }

    /**
     * A position is priced by how far along the track it is, so one scale serves every setup —
     * which is the whole reason the board states real positions rather than bands.
     */
    #[Test]
    public function theScaleRunsWithTheTrackRatherThanWithTheBands(): void
    {
        $game = GameBuilder::create()->persist($this->entityManager);

        $scale = $this->render($game)->filter('tfoot td');

        $this->assertCount(16, $scale);
        $this->assertSame(['0', '5', '10', '15'], \array_slice($scale->each(static fn (Crawler $node): string => trim($node->text())), 0, 4));
        $this->assertSame('75', trim($scale->last()->text()));
    }

    /**
     * The same position is a different age on a different setup: position 5 is still the Stone Age
     * for a slow starter, and already the Early Bronze Age on the standard track.
     */
    #[Test]
    public function oneMarkerSitsAtTheStoredPositionAndTakesItsAgeFromTheSetup(): void
    {
        $game = GameBuilder::create()->persist($this->entityManager);
        $alice = PlayerBuilder::named('Alice')->in($game)->withEmpire('minoa')->persist($this->entityManager);
        $bob = PlayerBuilder::named('Bob')->in($game)->withEmpire('assyria')->persist($this->entityManager);
        $alice->astPosition = 5;
        $alice->cities = 9; // a score that must not move the marker
        $bob->astPosition = 5;
        $this->entityManager->flush();

        $rows = $this->render($game)->filter('tr[data-empire]');

        $this->assertCount(16, $rows->eq(0)->filter('td'));
        $this->assertCount(1, $rows->eq(0)->filter('[data-marker]'));
        $this->assertCount(1, $rows->eq(0)->filter('td')->eq(5)->filter('[data-marker]'));
        $this->assertSame('Alice — Stone Age', $rows->eq(0)->filter('[data-marker]')->attr('title'));
        $this->assertSame('Bob — Early Bronze Age', $rows->eq(1)->filter('[data-marker]')->attr('title'));
    }

    #[Test]
    public function requirementsReadAsOneLinePerBand(): void
    {
        $game = GameBuilder::create()->persist($this->entityManager);

        $lines = $this->render($game)->filter('ul li small');

        $this->assertSame(
            ['none', 'none', '2 cities', '3 cities · 3 advances', '3 cities · 3 advances · min cost 100', '4 cities · 2 advances · min cost 200', '5 cities · 3 advances · min cost 200'],
            $lines->each(static fn (Crawler $node): string => trim($node->text())),
        );
    }

    /** A band is ticked once somebody stands in it: the list tracks the game, not one player. */
    #[Test]
    public function bandsAreTickedUpToTheFurthestPlayer(): void
    {
        $game = GameBuilder::create()->persist($this->entityManager);
        PlayerBuilder::named('Alice')->in($game)->withEmpire('minoa')->persist($this->entityManager);
        $bob = PlayerBuilder::named('Bob')->in($game)->withEmpire('saba')->persist($this->entityManager);
        $bob->astPosition = 7;
        $this->entityManager->flush();

        $ticked = $this->render($game)->filter('ul li[data-reached]');

        $this->assertCount(3, $ticked, 'Start, Stone Age and Early Bronze Age are behind the leader.');
    }

    /** @return list<int> the columns on which this row's empire enters a new age */
    private function ageStartsOf(Crawler $row): array
    {
        $cells = $row->filter('td')->each(static fn (Crawler $cell): bool => null !== $cell->attr('data-age-start'));

        return array_values(array_keys(array_filter($cells)));
    }

    private function render(Game $game): Crawler
    {
        return new Crawler($this->createLiveComponent('BandTimeline', ['game' => $game])->render()->toString());
    }
}
