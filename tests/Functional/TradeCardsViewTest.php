<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\State\Game;
use App\State\Region;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\Request;
use App\Tests\Support\Fixture\GameBuilder;

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
        $crawler = $this->goToTradeCards(GameBuilder::create()->withPlayerCount(9)->withRegion(Region::West)->persist($this->entityManager));

        $this->assertResponseIsSuccessful();
        $this->assertCount(1, $crawler->filter('table'));
    }

    #[Test]
    public function aGameWithNoDefinedDistributionReadsAsEmptyRatherThanAsABrokenPage(): void
    {
        $game = GameBuilder::create()->withPlayerCount(3)->withRegion(null)->persist($this->entityManager);

        $crawler = $this->goToTradeCards($game);

        $this->assertResponseIsSuccessful();
        $this->assertCount(0, $crawler->filter('table'));
        $this->assertCount(1, $crawler->filter('main > p'));
    }

    private function goToTradeCards(Game $game): Crawler
    {
        return $this->client->request(Request::METHOD_GET, '/'.$game->slug.'/trade-cards');
    }
}
