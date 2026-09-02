<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\State\Game;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\Request;
use App\Tests\Support\Fixture\GameBuilder;
use App\Tests\Support\Fixture\Tables;

final class DashboardTabsTest extends WebTestCase
{
    #[Test]
    #[DataProvider('provideTheBarOpensOnTheRosterWhateverTheQueryStringSaysCases')]
    public function theBarOpensOnTheRosterWhateverTheQueryStringSays(string $query): void
    {
        $client = self::createClient();
        $game = $this->game();

        $crawler = $client->request(Request::METHOD_GET, '/'.$game->slug.$query);

        $this->assertResponseIsSuccessful();
        $this->assertCount(1, $crawler->filter('menu input[checked]'));
        $this->assertSame('roster', $crawler->filter('menu input[checked]')->attr('value'));
    }

    /** @return iterable<string, array{string}> */
    public static function provideTheBarOpensOnTheRosterWhateverTheQueryStringSaysCases(): iterable
    {
        yield 'no query at all' => [''];

        yield 'a tab the bar knows' => ['?tab=nav'];

        yield 'another one' => ['?tab=ast'];

        yield 'anything invented' => ['?tab=elsewhere'];
    }

    /** The assertion that fails the day someone reaches for a controller. */
    #[Test]
    public function theBarSwitchesPanelsWithoutAnyScript(): void
    {
        $client = self::createClient();
        $game = $this->game();

        $crawler = $client->request(Request::METHOD_GET, '/'.$game->slug);
        $radios = $crawler->filter('menu input[type="radio"]');

        $this->assertNull($crawler->filter('main')->attr('data-controller'));
        $this->assertSame(
            ['tab', 'tab', 'tab', 'tab'],
            $radios->each(static fn (Crawler $radio): ?string => $radio->attr('name')),
        );
        $this->assertSame(
            ['roster', 'ast', 'nav', 'help'],
            $radios->each(static fn (Crawler $radio): ?string => $radio->attr('value')),
        );
        $this->assertSame(
            ['tab-roster', 'tab-ast', 'tab-nav', 'tab-help'],
            $crawler->filter('menu label')->each(static fn (Crawler $label): ?string => $label->attr('for')),
        );
    }

    #[Test]
    public function everyPanelIsServedInOneResponse(): void
    {
        $client = self::createClient();
        $game = $this->game();

        $crawler = $client->request(Request::METHOD_GET, '/'.$game->slug);

        $this->assertCount(1, $crawler->filter('#panel-nav'));
        $this->assertCount(1, $crawler->filter('#panel-roster'));
        $this->assertCount(1, $crawler->filter('#panel-ast'));
        $this->assertCount(1, $crawler->filter('#panel-help'));
    }

    #[Test]
    public function theHelpPanelIsTheWayInToTheTradeCardDistribution(): void
    {
        $client = self::createClient();
        $game = $this->game();

        $crawler = $client->request(Request::METHOD_GET, '/'.$game->slug);

        $this->assertCount(1, $crawler->filter('#panel-help a[href="/'.$game->slug.'/trade-cards"]'));
    }

    /**
     * A game with nobody at it, so the single dialog is Navigation's operator code: a seated table
     * adds one per seat and the count stops meaning anything.
     */
    #[Test]
    public function thePanelsRegroupTheStackWithoutDuplicatingIt(): void
    {
        $client = self::createClient();
        $game = GameBuilder::create()->persist(self::getContainer()->get(EntityManagerInterface::class));

        $crawler = $client->request(Request::METHOD_GET, '/'.$game->slug);

        $this->assertCount(1, $crawler->filter('#panel-nav nav'));
        $this->assertCount(1, $crawler->filter('#panel-roster table'));
        $this->assertCount(1, $crawler->filter('#panel-ast table'));
        $this->assertCount(1, $crawler->filter('main dialog'));
    }

    private function game(): Game
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);

        return Tables::westTable($entityManager);
    }
}
