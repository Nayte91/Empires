<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\State\Game;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\Request;

/**
 * The page a player opens mid-game to count a stack. Two things about it are worth pinning from the
 * outside: it reads the one configuration being played rather than the whole book, and the dot it
 * inherits from the printed table survives the whole way to the screen as a dash and not as a zero.
 */
final class TradeCardsViewTest extends WebTestCase
{
    private KernelBrowser $client; // @phpstan-ignore property.uninitialized (initialized in setUp)
    private EntityManagerInterface $entityManager; // @phpstan-ignore property.uninitialized (initialized in setUp)

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
    }

    /** Canary: one game, one column, and the heading naming which one the reader is looking at. */
    #[Test]
    public function theTradeCardsPageServesTheOneColumnOfTheGameBeingPlayed(): void
    {
        $crawler = $this->goToTradeCards($this->createGame(playerCount: 9, region: 'west'));

        $this->assertResponseIsSuccessful();
        $this->assertCount(1, $crawler->filter('table'));
        $this->assertSame('9 players', trim($crawler->filter('caption')->text()));
    }

    /** From twelve players the pool splits, so the same page has to answer with two columns instead of one. */
    #[Test]
    public function aSplitPoolIsServedAsTwoBlockColumnsUnderOneCaption(): void
    {
        $crawler = $this->goToTradeCards($this->createGame(playerCount: 12, region: null));

        $this->assertSame('West block / East block', trim($crawler->filter('caption')->text()));
        $this->assertCount(5, $crawler->filter('thead th[scope="col"]'));
    }

    /**
     * The end of the chain the registry test guards at its source: a card out of one block must read
     * as a dash on the page. A zero anywhere in the body would mean the distinction died in transit
     * and the panel is inviting players to draw a card that is not in the box.
     */
    #[Test]
    public function aCardOutOfOneBlockRendersADashAndNoCellAnywhereReadsZero(): void
    {
        $crawler = $this->goToTradeCards($this->createGame(playerCount: 12, region: null));

        $cells = $crawler->filter('tbody td')->each(static fn (Crawler $cell): string => trim($cell->text()));

        $this->assertContains('—', $cells);
        $this->assertNotContains('0', $cells);
    }

    /** The stack a card belongs to is the first thing a player looks for, so each stack is one spanned row group. */
    #[Test]
    public function everyStackIsOneRowGroupWhoseHeaderSpansExactlyItsOwnRows(): void
    {
        $crawler = $this->goToTradeCards($this->createGame(playerCount: 9, region: 'west'));

        $headers = $crawler->filter('tbody th[scope="rowgroup"]');
        $rowsPerStack = array_count_values($crawler->filter('tbody tr[data-stack]')->each(static fn (Crawler $row): string => (string) $row->attr('data-stack')));

        $this->assertSame(array_map(strval(...), range(1, 9)), $headers->each(static fn (Crawler $header): string => trim($header->text())));
        $this->assertSame(array_values($rowsPerStack), $headers->each(static fn (Crawler $header): int => (int) $header->attr('rowspan')));
    }

    /**
     * Below ten players a game is East or West and never both, so a region-less one names no
     * configuration. It has to read as a page with nothing to show, not as a page that failed.
     */
    #[Test]
    public function aGameWithNoDefinedDistributionReadsAsEmptyRatherThanAsABrokenPage(): void
    {
        $crawler = $this->goToTradeCards($this->createGame(playerCount: 3, region: null));

        $this->assertResponseIsSuccessful();
        $this->assertCount(0, $crawler->filter('table'));
        $this->assertCount(1, $crawler->filter('hgroup#page-title h1'));
        $this->assertCount(1, $crawler->filter('p'));
    }

    private function goToTradeCards(Game $game): Crawler
    {
        return $this->client->request(Request::METHOD_GET, '/'.$game->slug.'/trade-cards');
    }

    private function createGame(int $playerCount, ?string $region): Game
    {
        $game = new Game();
        $game->playerCount = $playerCount;
        $game->region = $region;

        $this->entityManager->persist($game);
        $this->entityManager->flush();

        return $game;
    }
}
