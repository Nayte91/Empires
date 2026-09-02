<?php

declare(strict_types=1);

namespace App\Tests\Component;

use App\Tests\Support\Fixture\GameBuilder;
use App\Tests\Support\Fixture\PlayerBuilder;
use App\Tests\Support\Fixture\Tables;
use App\Tests\Support\GameFixtureTrait;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\UX\TwigComponent\Test\InteractsWithTwigComponents;

final class RosterTest extends WebTestCase
{
    use GameFixtureTrait;
    use InteractsWithTwigComponents;

    #[Test]
    public function rendersCitiesAndPopulationColumnsWithoutPoints(): void
    {
        $game = Tables::westTable($this->entityManager);

        $rendered = $this->renderTwigComponent('Roster', ['game' => $game])->toString();

        $this->assertStringContainsString('Cities', $rendered);
        $this->assertStringContainsString('Population', $rendered);
        $this->assertStringContainsString('Treasury', $rendered);
        $this->assertStringNotContainsString('Points', $rendered);
    }

    #[Test]
    public function rendersPlayerTreasuryValue(): void
    {
        $game = GameBuilder::create()->persist($this->entityManager);
        PlayerBuilder::named('Alice')->in($game)->withTreasury(12)->persist($this->entityManager);

        $rendered = $this->renderTwigComponent('Roster', ['game' => $game])->toString();
        $row = new Crawler($rendered)->filter('tbody tr');

        $this->assertStringContainsString('(Alice)', $row->filter('th[data-empire]')->text());
        $this->assertSame('12', trim($row->filter('td')->eq(0)->text()));
    }

    #[Test]
    public function empireCellNamesTheEmpireAdjectiveThenThePlayerInParentheses(): void
    {
        $game = GameBuilder::create()->persist($this->entityManager);
        PlayerBuilder::named('Alice')->in($game)->withEmpire('minoa')->persist($this->entityManager);

        $rendered = $this->renderTwigComponent('Roster', ['game' => $game])->toString();
        $crawler = new Crawler($rendered);

        $this->assertSame('minoan', trim($crawler->filter('tbody tr th[data-empire] b')->text()));
        $this->assertSame('(Alice)', trim($crawler->filter('tbody tr th[data-empire] small[data-player]')->text()));
    }

    #[Test]
    public function rendersDefaultStatColumnsInTheNewOrder(): void
    {
        $game = GameBuilder::create()->persist($this->entityManager);
        PlayerBuilder::named('Alice')->in($game)->persist($this->entityManager);

        $rendered = $this->renderTwigComponent('Roster', ['game' => $game])->toString();
        $cells = new Crawler($rendered)->filter('tbody tr td');

        $this->assertSame(
            ['Treasury', 'Population', 'Cities', 'Advances'],
            $cells->each(static fn (Crawler $cell): ?string => $cell->attr('data-label')),
        );
        $this->assertSame(['0', '1', '0', '0'], $cells->each(static fn (Crawler $cell): string => trim($cell->text())));
    }

    #[Test]
    public function shipsAndCardsAreNotDisplayedAtAll(): void
    {
        $game = GameBuilder::create()->persist($this->entityManager);
        PlayerBuilder::named('Alice')->in($game)->withShips(4)->withCards(7)->persist($this->entityManager);

        $rendered = $this->renderTwigComponent('Roster', ['game' => $game])->toString();
        $cells = new Crawler($rendered)->filter('tbody tr td');

        $this->assertStringNotContainsString('Ships', $rendered);
        $this->assertStringNotContainsString('Cards', $rendered);
        $this->assertSame([], array_intersect(['4', '7'], $cells->each(static fn (Crawler $cell): string => trim($cell->text()))));
    }

    #[Test]
    public function theNameCellIsPlainTextCarryingNoLinkNorQrCode(): void
    {
        $game = Tables::westTable($this->entityManager);

        $rendered = $this->renderTwigComponent('Roster', ['game' => $game])->toString();

        $this->assertStringContainsString('<small data-player>(Alice)</small>', $rendered);
        $this->assertStringNotContainsString('<svg', $rendered);
        $this->assertStringNotContainsString('<dialog', $rendered);
    }

    #[Test]
    public function noVictoryPointColumnIsRendered(): void
    {
        $game = GameBuilder::create()->persist($this->entityManager);
        PlayerBuilder::named('Alice')->in($game)->withAdvances(['advanced_military'])->withCities(5)->persist($this->entityManager); // 6 points + 5 cities = 11 victory points in total

        $rendered = $this->renderTwigComponent('Roster', ['game' => $game])->toString();

        $cells = new Crawler($rendered)->filter('tbody tr td');

        $this->assertStringNotContainsString('VP', $rendered);
        $this->assertStringNotContainsString('data-medal', $rendered);
        $this->assertNotContains('11', $cells->each(static fn (Crawler $cell): string => trim($cell->text())));
    }

    #[Test]
    public function playersAreOrderedByPlayOrderRatherThanByScore(): void
    {
        $game = GameBuilder::create()->persist($this->entityManager);
        PlayerBuilder::named('Alice')->in($game)->withEmpire('minoa')->withCities(5)->withCensus(2)->persist($this->entityManager);
        PlayerBuilder::named('Bob')->in($game)->withEmpire('saba')->withCensus(9)->persist($this->entityManager);
        PlayerBuilder::named('Carl')->in($game)->withEmpire('assyria')->withAdvances(['military'])->withCensus(20)->persist($this->entityManager);

        $rendered = $this->renderTwigComponent('Roster', ['game' => $game])->toString();

        $this->assertLessThan(strpos($rendered, 'Alice'), strpos($rendered, 'Bob'), 'Higher census (Bob) plays before lower census (Alice).');
        $this->assertLessThan(strpos($rendered, 'Carl'), strpos($rendered, 'Alice'), 'Military owner (Carl) plays last whatever their census.');
    }

    #[Test]
    public function theMilitaryAdvanceIsFlaggedNextToTheEmpireItBelongsTo(): void
    {
        $game = GameBuilder::create()->persist($this->entityManager);
        PlayerBuilder::named('Alice')->in($game)->withEmpire('minoa')->withAdvances(['military'])->persist($this->entityManager);
        PlayerBuilder::named('Bob')->in($game)->withEmpire('saba')->persist($this->entityManager);

        $rendered = $this->renderTwigComponent('Roster', ['game' => $game])->toString();
        $rows = new Crawler($rendered)->filter('tbody tr');

        $this->assertSame('1. sabaean (Bob)', preg_replace('/\s+/u', ' ', trim($rows->eq(0)->filter('th[data-empire]')->text())));
        $this->assertSame('2. minoan (Alice) ⚔', preg_replace('/\s+/u', ' ', trim($rows->eq(1)->filter('th[data-empire]')->text())), 'The military owner plays last, and its people carries the ⚔ flag.');
    }

    #[Test]
    public function eachRowIsNumberedByItsPlaceInTheCensusOrder(): void
    {
        $game = GameBuilder::create()->persist($this->entityManager);
        PlayerBuilder::named('Alice')->in($game)->withEmpire('minoa')->withCensus(2)->persist($this->entityManager);
        PlayerBuilder::named('Bob')->in($game)->withEmpire('saba')->withCensus(9)->persist($this->entityManager);
        PlayerBuilder::named('Carl')->in($game)->withEmpire('assyria')->withAdvances(['military'])->withCensus(20)->persist($this->entityManager);

        $crawler = new Crawler($this->renderTwigComponent('Roster', ['game' => $game])->toString());

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

        $rendered = $this->renderTwigComponent('Roster', ['game' => $game])->toString();

        $this->assertStringStartsWith('Turn 7', trim(new Crawler($rendered)->filter('caption')->text()));
    }

    #[Test]
    public function theRosterOffersTheTargetItsPushReplaces(): void
    {
        $game = Tables::westTable($this->entityManager);

        $rendered = $this->renderTwigComponent('Roster', ['game' => $game])->crawler();

        $this->assertSame('roster', $rendered->filter('table')->attr('data-region'));
        $this->assertCount(0, $rendered->filter('[data-controller]'), 'It drives no controller: it is replaced, not refreshed.');
    }
}
