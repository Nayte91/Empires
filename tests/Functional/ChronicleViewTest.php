<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\Request;
use App\State\Game;
use App\Tests\Support\Fixture\GameBuilder;
use App\Tests\Support\Fixture\PlayerBuilder;
use App\Tests\Support\Fixture\Tables;

final class ChronicleViewTest extends WebTestCase
{
    private KernelBrowser $client; // @phpstan-ignore property.uninitialized (initialized in setUp)
    private EntityManagerInterface $entityManager; // @phpstan-ignore property.uninitialized (initialized in setUp)

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
    }

    #[Test]
    public function aGameStillRunningServesTheDashboard(): void
    {
        $game = Tables::westTable($this->entityManager);

        $crawler = $this->client->request(Request::METHOD_GET, '/'.$game->slug);

        $this->assertResponseIsSuccessful();
        $this->assertGreaterThan(0, $crawler->filter('caption#roster')->count());
    }

    #[Test]
    public function aFinishedGameServesTheChronicleAtTheVerySameAddress(): void
    {
        $game = $this->finishedGame();

        $crawler = $this->client->request(Request::METHOD_GET, '/'.$game->slug);

        $this->assertResponseIsSuccessful();
        $this->assertFalse($this->client->getResponse()->isRedirect());
        $this->assertSame('/'.$game->slug, $this->client->getRequest()->getPathInfo());
        $this->assertGreaterThan(0, $crawler->filter('canvas[data-controller~="symfony--ux-chartjs--chart"]')->count());
    }

    #[Test]
    public function theChronicleDropsTheRoster(): void
    {
        $game = $this->finishedGame();

        $crawler = $this->client->request(Request::METHOD_GET, '/'.$game->slug);

        $this->assertCount(0, $crawler->filter('caption#roster'));
    }

    #[Test]
    public function theChronicleDropsTheWayInToTheOperatorBoardThatTheDashboardOffers(): void
    {
        $running = Tables::westTable($this->entityManager);

        $finished = $this->finishedGame();

        $onDashboard = $this->client->request(Request::METHOD_GET, '/'.$running->slug)->filter('a[href$="/operator/board"]')->count();
        $onChronicle = $this->client->request(Request::METHOD_GET, '/'.$finished->slug)->filter('a[href$="/operator/board"]')->count();

        $this->assertGreaterThan(0, $onDashboard);
        $this->assertSame(0, $onChronicle);
    }

    #[Test]
    public function theChronicleKeepsTheAstBoardButDropsItsRequirements(): void
    {
        $game = $this->finishedGame();

        $crawler = $this->client->request(Request::METHOD_GET, '/'.$game->slug);

        $this->assertGreaterThan(0, $crawler->filter('table.ast')->count());
        $this->assertCount(0, $crawler->filter('section h3'));
    }

    #[Test]
    public function theBoardsCaptionCarriesTheFinishedMarkOnlyOnceTheGameIsFinished(): void
    {
        $running = Tables::westTable($this->entityManager);

        $finished = $this->finishedGame();

        $onDashboard = $this->client->request(Request::METHOD_GET, '/'.$running->slug)->filter('caption#ast[data-finished]')->count();
        $onChronicle = $this->client->request(Request::METHOD_GET, '/'.$finished->slug)->filter('caption#ast[data-finished]')->count();

        $this->assertSame(1, $onChronicle);
        $this->assertSame(0, $onDashboard);
    }

    #[Test]
    public function theChronicleIsOneScreenOfThreeTabsSwitchedWithoutAnyScript(): void
    {
        $game = $this->finishedGame();

        $crawler = $this->client->request(Request::METHOD_GET, '/'.$game->slug);

        $this->assertNull($crawler->filter('main')->attr('data-controller'));
        $this->assertSame(
            ['ast', 'evolution', 'nav'],
            $crawler->filter('menu input[type="radio"]')->each(static fn (Crawler $radio): ?string => $radio->attr('value')),
        );
        $this->assertSame('ast', $crawler->filter('menu input[checked]')->attr('value'));
        $this->assertCount(1, $crawler->filter('#panel-ast table.ast'));
        $this->assertCount(1, $crawler->filter('#panel-evolution canvas'));
        $this->assertCount(1, $crawler->filter('#panel-nav nav'));
    }

    private function finishedGame(): Game
    {
        $game = GameBuilder::create()->finished()->persist($this->entityManager);
        PlayerBuilder::named('Alice')->in($game)->withEmpire('minoa')->persist($this->entityManager);

        return $game;
    }
}
