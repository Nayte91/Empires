<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use App\Tests\Support\Fixture\Tables;

final class GameCreationTest extends WebTestCase
{
    #[Test]
    public function dashboardIsAccessibleForAnExistingGame(): void
    {
        $client = self::createClient();
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);

        $game = Tables::westTable($entityManager);

        $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/'.$game->slug);

        $this->assertResponseIsSuccessful();
    }

    #[Test]
    public function playerShopRouteIsDirectlyAccessible(): void
    {
        $client = self::createClient();
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);

        $player = Tables::seat(Tables::westTable($entityManager), 'Alice');
        $game = $player->game;

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
