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
use App\Tests\Support\Fixture\GameBuilder;
use App\Tests\Support\Fixture\Tables;

final class TradeCardsViewTest extends WebTestCase
{
    private KernelBrowser $client; // @phpstan-ignore property.uninitialized (initialized in setUp)
    private EntityManagerInterface $entityManager; // @phpstan-ignore property.uninitialized (initialized in setUp)

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
    }

    #[Test]
    public function theTradeCardsPageServesTheOneColumnOfTheGameBeingPlayed(): void
    {
        $crawler = $this->goToTradeCards(Tables::typicalTable($this->entityManager));

        $this->assertResponseIsSuccessful();
        $this->assertCount(1, $crawler->filter('table'));
        $this->assertSame('9 players', trim($crawler->filter('caption')->text()));
    }

    #[Test]
    public function aSplitPoolIsServedAsTwoBlockColumnsUnderOneCaption(): void
    {
        $crawler = $this->goToTradeCards(Tables::grandTable($this->entityManager));

        $this->assertSame('West block / East block', trim($crawler->filter('caption')->text()));
        $this->assertCount(5, $crawler->filter('thead th[scope="col"]'));
    }

    #[Test]
    public function aCardOutOfOneBlockRendersADashAndNoCellAnywhereReadsZero(): void
    {
        $crawler = $this->goToTradeCards(Tables::grandTable($this->entityManager));

        $cells = $crawler->filter('tbody td')->each(static fn (Crawler $cell): string => trim($cell->text()));

        $this->assertContains('—', $cells);
        $this->assertNotContains('0', $cells);
    }

    #[Test]
    public function everyStackIsOneRowGroupWhoseHeaderSpansExactlyItsOwnRows(): void
    {
        $crawler = $this->goToTradeCards(Tables::typicalTable($this->entityManager));

        $headers = $crawler->filter('tbody th[scope="rowgroup"]');
        $rowsPerStack = array_count_values($crawler->filter('tbody tr[data-stack]')->each(static fn (Crawler $row): string => (string) $row->attr('data-stack')));

        $this->assertSame(array_map(strval(...), range(1, 9)), $headers->each(static fn (Crawler $header): string => trim($header->text())));
        $this->assertSame(array_values($rowsPerStack), $headers->each(static fn (Crawler $header): int => (int) $header->attr('rowspan')));
    }

    #[Test]
    public function aGameWithNoDefinedDistributionReadsAsEmptyRatherThanAsABrokenPage(): void
    {
        $game = GameBuilder::create()->withPlayerCount(3)->withRegion(null)->persist($this->entityManager);

        $crawler = $this->goToTradeCards($game);

        $this->assertResponseIsSuccessful();
        $this->assertCount(0, $crawler->filter('table'));
        $this->assertSame($game->slug, trim($crawler->filter('#page-title h1')->text()));
        $this->assertCount(1, $crawler->filter('#page-title ~ p'));
    }

    #[Test]
    public function theNameOfTheDistributionReadsAsASectionHeading(): void
    {
        $crawler = $this->goToTradeCards(Tables::typicalTable($this->entityManager));

        $this->assertSame('Trade card distribution', trim($crawler->filter('h2')->text()));
    }

    private function goToTradeCards(Game $game): Crawler
    {
        return $this->client->request(Request::METHOD_GET, '/'.$game->slug.'/trade-cards');
    }
}
