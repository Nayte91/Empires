<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\GameSession;
use App\Entity\Player;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\UX\LiveComponent\Test\InteractsWithLiveComponents;

final class GameCreationTest extends WebTestCase
{
    use InteractsWithLiveComponents;

    #[Test]
    public function dashboardIsAccessibleForAnExistingGame(): void
    {
        $client = self::createClient();
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);

        $game = new GameSession();
        $entityManager->persist($game);
        $entityManager->flush();

        $client->request('GET', '/game/'.$game->slug);

        self::assertResponseIsSuccessful();
    }

    #[Test]
    public function operatorConsoleOrderCreationPanelListsPlayersWithALinkToTheirShop(): void
    {
        $client = self::createClient();
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);

        $game = new GameSession();
        $player = new Player($game, 'Alice');
        $entityManager->persist($game);
        $entityManager->flush();

        // The kiosk link only appears once an order is being created (the
        // console's cashier panel is the sole remaining surface for it).
        $rendered = $this->createLiveComponent('OperatorConsole', ['game' => $game], $client)
            ->call('startOrder')
            ->render()
        ;

        self::assertStringContainsString('Alice', $rendered->toString());

        $link = $rendered->crawler()->filter('a[href*="'.$player->slug.'"]')->link();
        self::assertStringContainsString($player->slug, $link->getUri());

        $client->request('GET', '/game/'.$game->slug.'/player/'.$player->slug.'/shop');
        self::assertResponseIsSuccessful();
    }

    #[Test]
    public function unknownGameSlugReturnsNotFound(): void
    {
        $client = self::createClient();
        $client->request('GET', '/game/does-not-exist');

        self::assertResponseStatusCodeSame(404);
    }
}
