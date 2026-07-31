<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\State\Game;
use App\State\Player;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\Request;

/**
 * A finished game keeps its one address and changes what it answers there. The point of pinning it
 * from the outside is that nothing about the URL says so: only the state of the game does, and the
 * swap happens inside the controller, where a redirect would have been the obvious wrong answer.
 */
final class ChronicleViewTest extends WebTestCase
{
    private KernelBrowser $client; // @phpstan-ignore property.uninitialized (initialized in setUp)
    private EntityManagerInterface $entityManager; // @phpstan-ignore property.uninitialized (initialized in setUp)

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
    }

    /** Canary for the unfinished half of the fork: the address still answers with the live dashboard. */
    #[Test]
    public function aGameStillRunningServesTheDashboard(): void
    {
        $game = $this->createGame(finished: false);

        $crawler = $this->client->request(Request::METHOD_GET, '/'.$game->slug);

        $this->assertResponseIsSuccessful();
        $this->assertGreaterThan(0, $crawler->filter('table.roster caption')->count());
    }

    /** Canary for the finished half: same address, no redirect, and the chart in place of the console. */
    #[Test]
    public function aFinishedGameServesTheChronicleAtTheVerySameAddress(): void
    {
        $game = $this->createGame(finished: true);

        $crawler = $this->client->request(Request::METHOD_GET, '/'.$game->slug);

        $this->assertResponseIsSuccessful();
        $this->assertFalse($this->client->getResponse()->isRedirect());
        $this->assertSame('/'.$game->slug, $this->client->getRequest()->getPathInfo());
        $this->assertGreaterThan(0, $crawler->filter('canvas[data-controller~="symfony--ux-chartjs--chart"]')->count());
    }

    /** The roster reads live counters the operator overwrites in place; a finished game has none to show. */
    #[Test]
    public function theChronicleDropsTheRoster(): void
    {
        $game = $this->createGame(finished: true);

        $crawler = $this->client->request(Request::METHOD_GET, '/'.$game->slug);

        $this->assertCount(0, $crawler->filter('table.roster'));
    }

    /** The console still answers, but every control on it refuses a finished game — so the way in goes. */
    #[Test]
    public function theChronicleDropsTheWayInToTheOperatorConsoleThatTheDashboardOffers(): void
    {
        $running = $this->createGame(finished: false);
        $finished = $this->createGame(finished: true);

        $onDashboard = $this->client->request(Request::METHOD_GET, '/'.$running->slug)->filter('a[href$="/operator"]')->count();
        $onChronicle = $this->client->request(Request::METHOD_GET, '/'.$finished->slug)->filter('a[href$="/operator"]')->count();

        $this->assertGreaterThan(0, $onDashboard);
        $this->assertSame(0, $onChronicle);
    }

    /** The board is the record of the game that was played; its requirements are advice for a game still on. */
    #[Test]
    public function theChronicleKeepsTheAstBoardButDropsItsRequirements(): void
    {
        $game = $this->createGame(finished: true);

        $crawler = $this->client->request(Request::METHOD_GET, '/'.$game->slug);

        $this->assertGreaterThan(0, $crawler->filter('table.ast')->count());
        $this->assertCount(0, $crawler->filter('section h3'));
    }

    #[Test]
    public function theBoardsCaptionSaysSoOnlyOnceTheGameIsFinished(): void
    {
        $running = $this->createGame(finished: false);
        $finished = $this->createGame(finished: true);

        $onDashboard = $this->client->request(Request::METHOD_GET, '/'.$running->slug)->filter('table.ast caption')->text();
        $onChronicle = $this->client->request(Request::METHOD_GET, '/'.$finished->slug)->filter('table.ast caption')->text();

        $this->assertStringEndsWith('— finished', trim($onChronicle));
        $this->assertStringNotContainsString('finished', $onDashboard);
    }

    /**
     * The chronicle is the same phone screen as the dashboard, over its own three panels: the board,
     * the chart that only exists here, and the way to each seat. It opens on the board, and switching
     * asks nothing of the server — the same radio group, and no controller anywhere on the page.
     */
    #[Test]
    public function theChronicleIsOneScreenOfThreeTabsSwitchedWithoutAnyScript(): void
    {
        $game = $this->createGame(finished: true);

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

    private function createGame(bool $finished): Game
    {
        $game = new Game();
        $game->currentTurn = 4;
        new Player($game, 'Alice', 'minoa');
        new Player($game, 'Bob', 'hellas');

        if ($finished) {
            $game->finishedAt = new \DateTimeImmutable();
        }

        $this->entityManager->persist($game);
        $this->entityManager->flush();

        return $game;
    }
}
