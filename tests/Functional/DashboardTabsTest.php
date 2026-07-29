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
 * The phone dashboard is one screen with three tabs. The tab lives in the query string rather
 * than in the client, which is what lets a reload mid-game come back to the same panel — and
 * what lets this test assert on it at all.
 */
final class DashboardTabsTest extends WebTestCase
{
    #[Test]
    #[DataProvider('provideTabCases')]
    public function theRequestedTabIsTheOneMarkedCurrent(string $query, string $expected): void
    {
        $client = self::createClient();
        $game = $this->game();

        $crawler = $client->request(Request::METHOD_GET, '/'.$game->slug.$query);

        $this->assertResponseIsSuccessful();
        $this->assertSame($expected, $crawler->filter('main')->attr('data-tab'));
        $this->assertSame('?tab='.$expected, $crawler->filter('nav [aria-current]')->attr('href'));
    }

    /** @return iterable<string, array{string, string}> */
    public static function provideTabCases(): iterable
    {
        yield 'no query opens on the seats' => ['', 'nav'];

        yield 'the standings' => ['?tab=score', 'score'];

        yield 'the timeline' => ['?tab=ast', 'ast'];

        yield 'anything invented falls back' => ['?tab=elsewhere', 'nav'];
    }

    /** All three panels ship in one response: switching tabs is a paint, never a round trip. */
    #[Test]
    public function everyPanelIsServedWhicheverTabIsCurrent(): void
    {
        $client = self::createClient();
        $game = $this->game();

        $crawler = $client->request(Request::METHOD_GET, '/'.$game->slug.'?tab=ast');

        $this->assertCount(1, $crawler->filter('#panel-nav .seat-list'));
        $this->assertCount(1, $crawler->filter('#panel-score .standings'));
        $this->assertCount(1, $crawler->filter('#panel-ast .band-timeline'));
    }

    /** The wide dashboard is the fallback, so its own furniture must still be in the page. */
    #[Test]
    public function theWideDashboardStillShipsAlongsideThePhoneOne(): void
    {
        $client = self::createClient();
        $game = $this->game();

        $crawler = $client->request(Request::METHOD_GET, '/'.$game->slug);

        $this->assertCount(1, $crawler->filter('#panel-nav details.navigation'));
        $this->assertCount(1, $crawler->filter('#panel-score table.roster'));
        $this->assertCount(1, $crawler->filter('#panel-ast table.ast'));
    }

    /**
     * Switching tabs repaints rather than navigates — but the enhancement sits on top of real
     * links, so the href a shared URL or a middle-click follows is the one the server honours.
     */
    #[Test]
    public function eachTabIsARealLinkBeforeItIsAButton(): void
    {
        $client = self::createClient();
        $game = $this->game();

        $crawler = $client->request(Request::METHOD_GET, '/'.$game->slug);
        $tabs = $crawler->filter('nav a');

        $this->assertSame('tab', $crawler->filter('main')->attr('data-controller'));
        $this->assertSame(
            ['?tab=nav', '?tab=score', '?tab=ast'],
            $tabs->each(static fn (Crawler $tab): ?string => $tab->attr('href')),
        );
        $this->assertSame(
            ['nav', 'score', 'ast'],
            $tabs->each(static fn (Crawler $tab): ?string => $tab->attr('data-tab-panel-param')),
        );
        $this->assertSame(
            ['click->tab#show', 'click->tab#show', 'click->tab#show'],
            $tabs->each(static fn (Crawler $tab): ?string => $tab->attr('data-action')),
        );
    }

    /** Three destinations, three targets big enough to hit — and one shared QR dialog. */
    #[Test]
    public function theBarOffersThreeTabsAndTheListsShareOneDialog(): void
    {
        $client = self::createClient();
        $game = $this->game();

        $crawler = $client->request(Request::METHOD_GET, '/'.$game->slug);

        $this->assertCount(3, $crawler->filter('nav a'));
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
