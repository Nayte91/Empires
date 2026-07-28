<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\State\ASTVersion;
use App\State\Game;
use App\State\Player;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;

/**
 * The dashboard is now the only page carrying the A.S.T., so what used to be pinned on its own
 * route is pinned here: the board, its requirements, its ranking and its caption.
 */
final class DashboardViewTest extends WebTestCase
{
    /** Canary for the dashboard: it answers, and carries both parts of the board — the track and the requirements. */
    #[Test]
    public function dashboardContainsAstTableAndRequirementsSection(): void
    {
        $client = self::createClient();
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);

        $game = new Game();
        $entityManager->persist($game);
        $entityManager->flush();

        $crawler = $client->request(Request::METHOD_GET, '/'.$game->slug);

        $this->assertResponseIsSuccessful();
        $this->assertGreaterThan(0, $crawler->filter('table.ast')->count(), 'AST table should be present');
        $this->assertGreaterThan(0, $crawler->filter('section h3')->count(), 'Requirements section header should be present');
        $this->assertStringContainsString('Archaeological Succession Timeline', $crawler->filter('main')->html());
    }

    #[Test]
    public function astListsPlayersRankedByScore(): void
    {
        $client = self::createClient();
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);

        $game = new Game();
        $hellas = new Player($game, 'Bob');
        $hellas->empire = 'hellas';
        $hellas->cities = 2;
        $minoa = new Player($game, 'Alice');
        $minoa->empire = 'minoa';
        $minoa->cities = 7;
        $hatti = new Player($game, 'Kangoo');
        $hatti->empire = 'hatti';
        $hatti->cities = 4;
        $entityManager->persist($game);
        $entityManager->flush();

        $crawler = $client->request(Request::METHOD_GET, '/'.$game->slug);

        $this->assertResponseIsSuccessful();
        $order = $crawler->filter('table.ast tbody tr[data-empire]')->each(static fn ($node) => $node->attr('data-empire'));
        $this->assertSame(['minoa', 'hatti', 'hellas'], $order);
    }

    #[Test]
    #[DataProvider('provideCaptionNamesTheGamesAstVersionAndTurnCases')]
    public function captionNamesTheGamesAstVersionAndTurn(ASTVersion $astVersion, string $expectedCaption): void
    {
        $client = self::createClient();
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);

        $game = new Game();
        $game->astVersion = $astVersion;
        $game->currentTurn = 7;
        $entityManager->persist($game);
        $entityManager->flush();

        $crawler = $client->request(Request::METHOD_GET, '/'.$game->slug);

        $this->assertResponseIsSuccessful();
        $this->assertSame($expectedCaption, trim($crawler->filter('table.ast caption')->text()));
    }

    /** @return iterable<string, array{ASTVersion, string}> */
    public static function provideCaptionNamesTheGamesAstVersionAndTurnCases(): iterable
    {
        yield 'basic is the default version' => [ASTVersion::BASIC, 'Basic version — Turn 7'];

        yield 'expert game' => [ASTVersion::EXPERT, 'Expert version — Turn 7'];
    }

    #[Test]
    public function unknownGameSlugReturnsNotFound(): void
    {
        $client = self::createClient();
        $client->request(Request::METHOD_GET, '/does-not-exist');

        $this->assertResponseStatusCodeSame(404);
    }

    /** The board and the movement order used to have pages of their own; nothing must answer there now. */
    #[Test]
    public function theRetiredAstAndCensusPagesAreGone(): void
    {
        $client = self::createClient();
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);

        $game = new Game();
        $entityManager->persist($game);
        $entityManager->flush();

        $client->request(Request::METHOD_GET, '/'.$game->slug.'/ast');
        $this->assertResponseStatusCodeSame(404);

        $client->request(Request::METHOD_GET, '/'.$game->slug.'/census');
        $this->assertResponseStatusCodeSame(404);
    }
}
