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

/**
 * Under 60rem the dashboard is one screen with three tabs, switched by the browser alone: one radio
 * group in the bar, the panels shown or hidden off the checked one. Which tab is open is the
 * browser's business end to end — the server neither reads it nor writes it. Above 60rem the very
 * same markup is the stack it has always been, which is why every panel ships regardless, and why
 * nothing here is rendered twice.
 */
final class DashboardTabsTest extends WebTestCase
{
    /**
     * The query string is not a way in. This is the cost of holding the tab client-side and it is
     * asserted rather than left to be discovered: a link naming a tab is served the roster like any
     * other, so nothing downstream may go on building `?tab=` URLs.
     */
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

    /**
     * The bar carries no script at all: three radios of one group, each paired with the label that
     * drives it. This is the assertion that fails the day someone reaches for a controller.
     */
    #[Test]
    public function theBarSwitchesPanelsWithoutAnyScript(): void
    {
        $client = self::createClient();
        $game = $this->game();

        $crawler = $client->request(Request::METHOD_GET, '/'.$game->slug);
        $radios = $crawler->filter('menu input[type="radio"]');

        $this->assertNull($crawler->filter('main')->attr('data-controller'));
        $this->assertSame(
            ['tab', 'tab', 'tab'],
            $radios->each(static fn (Crawler $radio): ?string => $radio->attr('name')),
        );
        $this->assertSame(
            ['roster', 'ast', 'nav'],
            $radios->each(static fn (Crawler $radio): ?string => $radio->attr('value')),
        );
        $this->assertSame(
            ['tab-roster', 'tab-ast', 'tab-nav'],
            $crawler->filter('menu label')->each(static fn (Crawler $label): ?string => $label->attr('for')),
        );
    }

    /** All three panels ship in one response: switching tabs is a paint, never a round trip. */
    #[Test]
    public function everyPanelIsServedInOneResponse(): void
    {
        $client = self::createClient();
        $game = $this->game();

        $crawler = $client->request(Request::METHOD_GET, '/'.$game->slug);

        $this->assertCount(1, $crawler->filter('#panel-nav'));
        $this->assertCount(1, $crawler->filter('#panel-roster'));
        $this->assertCount(1, $crawler->filter('#panel-ast'));
    }

    /**
     * The panels only regroup what the stacked dashboard already showed — nothing is rendered a
     * second time for the phone. One navigation, one roster, one board: the counts are the point.
     */
    #[Test]
    public function thePanelsRegroupTheStackWithoutDuplicatingIt(): void
    {
        $client = self::createClient();
        $game = $this->game();

        $crawler = $client->request(Request::METHOD_GET, '/'.$game->slug);

        $this->assertCount(1, $crawler->filter('#panel-nav nav'));
        $this->assertCount(1, $crawler->filter('#panel-roster table'));
        $this->assertCount(1, $crawler->filter('#panel-ast table'));
        $this->assertCount(1, $crawler->filter('main dialog'));
    }

    private function game(): Game
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);

        $game = new Game();
        $entityManager->persist($game);
        $entityManager->flush();

        return $game;
    }
}
