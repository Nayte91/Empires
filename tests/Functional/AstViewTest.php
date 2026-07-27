<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\GameSession;
use App\Entity\Player;
use App\Game\ASTVersion;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;

final class AstViewTest extends WebTestCase
{
    /** Canary for the AST page: it answers, and carries both of its parts — the board and the requirements. */
    #[Test]
    public function astViewContainsAstTableAndRequirementsSection(): void
    {
        $client = self::createClient();
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);

        $game = new GameSession();
        $entityManager->persist($game);
        $entityManager->flush();

        $crawler = $client->request(Request::METHOD_GET, '/'.$game->slug.'/ast');

        $this->assertResponseIsSuccessful();
        $this->assertGreaterThan(0, $crawler->filter('table')->count(), 'AST table should be present');
        $this->assertGreaterThan(0, $crawler->filter('section h3')->count(), 'Requirements section header should be present');
    }

    #[Test]
    public function astViewListsPlayersRankedByEmpirePosition(): void
    {
        $client = self::createClient();
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);

        $game = new GameSession();
        $hellas = new Player($game, 'Bob');
        $hellas->empire = 'hellas';
        $minoa = new Player($game, 'Alice');
        $minoa->empire = 'minoa';
        $hatti = new Player($game, 'Kangoo');
        $hatti->empire = 'hatti';
        $entityManager->persist($game);
        $entityManager->flush();

        $crawler = $client->request(Request::METHOD_GET, '/'.$game->slug.'/ast');

        $this->assertResponseIsSuccessful();
        $order = $crawler->filter('tbody tr[data-empire]')->each(static fn ($node) => $node->attr('data-empire'));
        $this->assertSame(['minoa', 'hatti', 'hellas'], $order);
    }

    #[Test]
    public function astViewCaptionDisplaysBasicVersionByDefault(): void
    {
        $client = self::createClient();
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);

        $game = new GameSession();
        $entityManager->persist($game);
        $entityManager->flush();

        $crawler = $client->request(Request::METHOD_GET, '/'.$game->slug.'/ast');

        $this->assertResponseIsSuccessful();
        $this->assertSame('Basic version', trim($crawler->filter('caption')->text()));
    }

    #[Test]
    public function astViewCaptionDisplaysExpertVersionForAnExpertGame(): void
    {
        $client = self::createClient();
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);

        $game = new GameSession();
        $game->astVersion = ASTVersion::EXPERT;
        $entityManager->persist($game);
        $entityManager->flush();

        $crawler = $client->request(Request::METHOD_GET, '/'.$game->slug.'/ast');

        $this->assertResponseIsSuccessful();
        $this->assertSame('Expert version', trim($crawler->filter('caption')->text()));
    }

    #[Test]
    public function unknownGameSlugReturnsNotFound(): void
    {
        $client = self::createClient();
        $client->request(Request::METHOD_GET, '/does-not-exist/ast');

        $this->assertResponseStatusCodeSame(404);
    }
}
