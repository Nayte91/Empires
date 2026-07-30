<?php

declare(strict_types=1);

namespace App\Tests\Component;

use App\Tests\Support\Fixture\GameBuilder;
use App\Tests\Support\Fixture\PlayerBuilder;
use App\Tests\Support\GameFixtureTrait;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\UX\LiveComponent\Test\InteractsWithLiveComponents;

final class RosterTest extends WebTestCase
{
    use GameFixtureTrait;
    use InteractsWithLiveComponents;

    #[Test]
    public function rendersCitiesAndPopulationColumnsWithoutPoints(): void
    {
        $game = GameBuilder::create()->persist($this->entityManager);
        PlayerBuilder::named('Alice')->in($game)->persist($this->entityManager);
        PlayerBuilder::named('Bob')->in($game)->persist($this->entityManager);

        $rendered = $this->createLiveComponent('Roster', ['game' => $game])->render()->toString();

        $this->assertStringContainsString('Cities', $rendered);
        $this->assertStringContainsString('Population', $rendered);
        $this->assertStringContainsString('Treasury', $rendered);
        $this->assertStringNotContainsString('Points', $rendered);
    }

    #[Test]
    public function rendersPlayerTreasuryValue(): void
    {
        $game = GameBuilder::create()->persist($this->entityManager);
        $player = PlayerBuilder::named('Alice')->in($game)->persist($this->entityManager);
        $player->treasury = 12;
        $this->entityManager->flush();

        $rendered = $this->createLiveComponent('Roster', ['game' => $game])->render()->toString();
        $row = new Crawler($rendered)->filter('tbody tr');

        $this->assertStringContainsString('(Alice)', $row->filter('th[data-empire]')->text());
        $this->assertSame('12', trim($row->filter('td')->eq(0)->text()));
    }

    /** One identity cell, like the A.S.T. rows: the people first, the player they belong to in parentheses. */
    #[Test]
    public function empireCellNamesTheEmpireAdjectiveThenThePlayerInParentheses(): void
    {
        $game = GameBuilder::create()->persist($this->entityManager);
        PlayerBuilder::named('Alice')->in($game)->withEmpire('minoa')->persist($this->entityManager);

        $rendered = $this->createLiveComponent('Roster', ['game' => $game])->render()->toString();
        $crawler = new Crawler($rendered);

        $this->assertSame('minoan', trim($crawler->filter('tbody tr th[data-empire] b')->text()));
        $this->assertSame('(Alice)', trim($crawler->filter('tbody tr th[data-empire] small[data-player]')->text()));
    }

    #[Test]
    public function rendersDefaultStatColumnsInTheNewOrder(): void
    {
        $game = GameBuilder::create()->persist($this->entityManager);
        PlayerBuilder::named('Alice')->in($game)->persist($this->entityManager);

        $rendered = $this->createLiveComponent('Roster', ['game' => $game])->render()->toString();
        $cells = new Crawler($rendered)->filter('tbody tr td');

        $this->assertSame(
            ['Treasury', 'Population', 'Cities', 'Advances'],
            $cells->each(static fn (Crawler $cell): ?string => $cell->attr('data-label')),
        );
        $this->assertSame(['0', '1', '0', '0'], $cells->each(static fn (Crawler $cell): string => trim($cell->text())));
    }

    /** Two stats the table deliberately leaves to the player's own board. */
    #[Test]
    public function shipsAndCardsAreNotDisplayedAtAll(): void
    {
        $game = GameBuilder::create()->persist($this->entityManager);
        $player = PlayerBuilder::named('Alice')->in($game)->persist($this->entityManager);
        $player->ships = 4;
        $player->cards = 7;
        $this->entityManager->flush();

        $rendered = $this->createLiveComponent('Roster', ['game' => $game])->render()->toString();
        $cells = new Crawler($rendered)->filter('tbody tr td');

        $this->assertStringNotContainsString('Ships', $rendered);
        $this->assertStringNotContainsString('Cards', $rendered);
        $this->assertSame([], array_intersect(['4', '7'], $cells->each(static fn (Crawler $cell): string => trim($cell->text()))));
    }

    /** Reaching a view is Navigation's job: the table states scores and links to nothing. */
    #[Test]
    public function theNameCellIsPlainTextCarryingNoLinkNorQrCode(): void
    {
        $game = GameBuilder::create()->persist($this->entityManager);
        PlayerBuilder::named('Alice')->in($game)->persist($this->entityManager);

        $rendered = $this->createLiveComponent('Roster', ['game' => $game])->render()->toString();

        $this->assertStringContainsString('<small data-player>(Alice)</small>', $rendered);
        $this->assertStringNotContainsString('<svg', $rendered);
        $this->assertStringNotContainsString('<dialog', $rendered);
    }

    /** The score belongs to the A.S.T. board now: the roster must not grow a second one. */
    #[Test]
    public function noVictoryPointColumnIsRendered(): void
    {
        $game = GameBuilder::create()->persist($this->entityManager);
        $player = PlayerBuilder::named('Alice')->in($game)->persist($this->entityManager);
        $player->ownAdvances(['advanced_military']); // 6 points
        $player->cities = 5;                         // 11 victory points in total
        $this->entityManager->flush();

        $rendered = $this->createLiveComponent('Roster', ['game' => $game])->render()->toString();

        $cells = new Crawler($rendered)->filter('tbody tr td');

        $this->assertStringNotContainsString('VP', $rendered);
        $this->assertStringNotContainsString('data-medal', $rendered);
        $this->assertNotContains('11', $cells->each(static fn (Crawler $cell): string => trim($cell->text())));
    }

    /**
     * The table is read down the movement-phase order, not down the standings: the operator uses it
     * to call the players in turn.
     */
    #[Test]
    public function playersAreOrderedByPlayOrderRatherThanByScore(): void
    {
        $game = GameBuilder::create()->persist($this->entityManager);
        $alice = PlayerBuilder::named('Alice')->in($game)->withEmpire('minoa')->withCities(5)->persist($this->entityManager);
        $bob = PlayerBuilder::named('Bob')->in($game)->withEmpire('saba')->persist($this->entityManager);
        $carl = PlayerBuilder::named('Carl')->in($game)->withEmpire('assyria')->withAdvances(['military'])->persist($this->entityManager);
        $alice->census = 2;
        $bob->census = 9;
        $carl->census = 20;
        $this->entityManager->flush();

        $rendered = $this->createLiveComponent('Roster', ['game' => $game])->render()->toString();

        $this->assertLessThan(strpos($rendered, 'Alice'), strpos($rendered, 'Bob'), 'Higher census (Bob) plays before lower census (Alice).');
        $this->assertLessThan(strpos($rendered, 'Carl'), strpos($rendered, 'Alice'), 'Military owner (Carl) plays last whatever their census.');
    }

    #[Test]
    public function theMilitaryAdvanceIsFlaggedNextToTheEmpireItBelongsTo(): void
    {
        $game = GameBuilder::create()->persist($this->entityManager);
        PlayerBuilder::named('Alice')->in($game)->withEmpire('minoa')->withAdvances(['military'])->persist($this->entityManager);
        PlayerBuilder::named('Bob')->in($game)->withEmpire('saba')->persist($this->entityManager);

        $rendered = $this->createLiveComponent('Roster', ['game' => $game])->render()->toString();
        $rows = new Crawler($rendered)->filter('tbody tr');

        $this->assertSame('1. sabaean (Bob)', preg_replace('/\s+/u', ' ', trim($rows->eq(0)->filter('th[data-empire]')->text())));
        $this->assertSame('2. minoan (Alice) ⚔', preg_replace('/\s+/u', ' ', trim($rows->eq(1)->filter('th[data-empire]')->text())), 'The military owner plays last, and its people carries the ⚔ flag.');
    }

    /**
     * The order is the census order, and nothing on screen said so. The caption names it and each
     * row carries its place in it — as `1.`, never `#1`, which on a board game reads as first place
     * and would smuggle back the standings this table refuses to state.
     */
    #[Test]
    public function eachRowIsNumberedByItsPlaceInTheCensusOrder(): void
    {
        $game = GameBuilder::create()->persist($this->entityManager);
        $alice = PlayerBuilder::named('Alice')->in($game)->withEmpire('minoa')->persist($this->entityManager);
        $bob = PlayerBuilder::named('Bob')->in($game)->withEmpire('saba')->persist($this->entityManager);
        $carl = PlayerBuilder::named('Carl')->in($game)->withEmpire('assyria')->withAdvances(['military'])->persist($this->entityManager);
        $alice->census = 2;
        $bob->census = 9;
        $carl->census = 20;
        $this->entityManager->flush();

        $crawler = new Crawler($this->createLiveComponent('Roster', ['game' => $game])->render()->toString());

        $this->assertStringContainsString('Census order', $crawler->filter('caption')->text());
        $this->assertSame(
            ['1.', '2.', '3.'],
            $crawler->filter('tbody tr [data-rank]')->each(static fn (Crawler $rank): string => trim($rank->text())),
        );
        $this->assertSame(
            ['sabaean', 'minoan', 'assyrian'],
            $crawler->filter('tbody tr th[data-empire] b')->each(static fn (Crawler $cell): string => trim($cell->text())),
            'Highest census first, and the military owner last whatever their census.',
        );
    }

    #[Test]
    public function captionDisplaysTheCurrentTurn(): void
    {
        $game = GameBuilder::create()->persist($this->entityManager);
        $game->currentTurn = 7;
        $this->entityManager->flush();

        $rendered = $this->createLiveComponent('Roster', ['game' => $game])->render()->toString();

        $this->assertStringStartsWith('Turn 7', trim(new Crawler($rendered)->filter('caption')->text()));
    }

    #[Test]
    public function mercureRefreshFiltersOutOrderUpdatedButKeepsGameStateEvents(): void
    {
        $game = GameBuilder::create()->persist($this->entityManager);

        $rendered = $this->createLiveComponent('Roster', ['game' => $game])->render()->toString();

        $this->assertStringContainsString('data-mercure-refresh-events-value', $rendered);
        $this->assertStringContainsString('player-updated', $rendered);
        $this->assertStringContainsString('game-updated', $rendered);
        $this->assertStringNotContainsString('order-updated', $rendered);
    }
}
