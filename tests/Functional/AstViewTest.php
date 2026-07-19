<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\GameSession;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;

final class AstViewTest extends WebTestCase
{
    #[Test]
    public function astViewIsAccessibleForAnExistingGame(): void
    {
        $client = self::createClient();
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);

        $game = new GameSession();
        $entityManager->persist($game);
        $entityManager->flush();

        $client->request(Request::METHOD_GET, '/game/'.$game->slug.'/ast');

        self::assertResponseIsSuccessful();
    }

    #[Test]
    public function astViewContainsAstTable(): void
    {
        $client = self::createClient();
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);

        $game = new GameSession();
        $entityManager->persist($game);
        $entityManager->flush();

        $crawler = $client->request(Request::METHOD_GET, '/game/'.$game->slug.'/ast');

        self::assertResponseIsSuccessful();
        self::assertGreaterThan(0, $crawler->filter('table')->count(), 'AST table should be present');
    }

    #[Test]
    public function astViewContainsRequirementsSection(): void
    {
        $client = self::createClient();
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);

        $game = new GameSession();
        $entityManager->persist($game);
        $entityManager->flush();

        $crawler = $client->request(Request::METHOD_GET, '/game/'.$game->slug.'/ast');

        self::assertResponseIsSuccessful();
        self::assertGreaterThan(0, $crawler->filter('section h3')->count(), 'Requirements section header should be present');
    }

    #[Test]
    public function unknownGameSlugReturnsNotFound(): void
    {
        $client = self::createClient();
        $client->request(Request::METHOD_GET, '/game/does-not-exist/ast');

        self::assertResponseStatusCodeSame(404);
    }
}
