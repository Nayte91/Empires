<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\GameSession;
use App\Entity\Player;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class GameCreationTest extends WebTestCase
{
    #[Test]
    public function dashboardIsAccessibleForAnExistingGame(): void
    {
        $client = self::createClient();
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);

        $game = new GameSession();
        $entityManager->persist($game);
        $entityManager->flush();

        $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/'.$game->slug);

        $this->assertResponseIsSuccessful();
    }

    #[Test]
    public function playerShopRouteIsDirectlyAccessible(): void
    {
        $client = self::createClient();
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);

        $game = new GameSession();
        $player = new Player($game, 'Alice');
        $entityManager->persist($game);
        $entityManager->flush();

        // The operator console no longer links to the kiosk (PO decision): the
        // shop route itself must still be reachable directly (e.g. via a QR code).
        $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/'.$game->slug.'/player/'.$player->slug.'/shop');
        $this->assertResponseIsSuccessful();
    }

    #[Test]
    public function unknownGameSlugReturnsNotFound(): void
    {
        $client = self::createClient();
        $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/does-not-exist');

        $this->assertResponseStatusCodeSame(404);
    }
}
