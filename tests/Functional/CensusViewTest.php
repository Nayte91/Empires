<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\GameSession;
use App\Entity\Player;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;

final class CensusViewTest extends WebTestCase
{
    #[Test]
    public function censusViewIsAccessibleForAnExistingGame(): void
    {
        $client = self::createClient();
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);

        $game = new GameSession();
        $entityManager->persist($game);
        $entityManager->flush();

        $client->request(Request::METHOD_GET, '/game/'.$game->slug.'/census');

        self::assertResponseIsSuccessful();
    }

    #[Test]
    public function censusViewListsPlayersInMovementOrder(): void
    {
        $client = self::createClient();
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);

        $game = new GameSession();
        $lowCensus = new Player($game, 'Bob');
        $lowCensus->empire = 'saba';
        $lowCensus->census = 10;
        $militaryHighCensus = new Player($game, 'Alice');
        $militaryHighCensus->empire = 'minoa';
        $militaryHighCensus->census = 40;
        $militaryHighCensus->ownAdvances(['military']);
        $highCensus = new Player($game, 'Kangoo');
        $highCensus->empire = 'assyria';
        $highCensus->census = 30;
        $entityManager->persist($game);
        $entityManager->flush();

        $crawler = $client->request(Request::METHOD_GET, '/game/'.$game->slug.'/census');

        self::assertResponseIsSuccessful();
        $order = $crawler->filter('ol li')->each(static fn ($node) => $node->attr('data-empire'));
        self::assertSame(['assyria', 'saba', 'minoa'], $order);
    }

    #[Test]
    public function militaryPlayerDisplaysBadgeInTheRightRow(): void
    {
        $client = self::createClient();
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);

        $game = new GameSession();
        $nonMilitary = new Player($game, 'Bob');
        $nonMilitary->empire = 'saba';
        $nonMilitary->census = 10;
        $military = new Player($game, 'Alice');
        $military->empire = 'minoa';
        $military->census = 40;
        $military->ownAdvances(['military']);
        $entityManager->persist($game);
        $entityManager->flush();

        $crawler = $client->request(Request::METHOD_GET, '/game/'.$game->slug.'/census');

        self::assertResponseIsSuccessful();
        $militaryRow = $crawler->filter('ol li[data-empire="minoa"]');
        self::assertGreaterThan(0, $militaryRow->filter('[title="Military"]')->count(), 'Military badge should be present on the Military player row');
        $nonMilitaryRow = $crawler->filter('ol li[data-empire="saba"]');
        self::assertCount(0, $nonMilitaryRow->filter('[title="Military"]'), 'Military badge should be absent on the non-Military player row');
    }

    #[Test]
    public function unknownGameSlugReturnsNotFound(): void
    {
        $client = self::createClient();
        $client->request(Request::METHOD_GET, '/game/does-not-exist/census');

        self::assertResponseStatusCodeSame(404);
    }
}
