<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\GameSession;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class HomePageTest extends WebTestCase
{
    #[Test]
    public function homePageIsAccessible(): void
    {
        $client = self::createClient();

        $client->request('GET', '/');

        self::assertResponseIsSuccessful();
    }

    #[Test]
    public function homePageListsGamesInProgressButNotFinishedOnes(): void
    {
        $client = self::createClient();
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);

        $inProgressGame = new GameSession('in-progress-game');
        $finishedGame = new GameSession('finished-game');
        $finishedGame->finishedAt = new \DateTimeImmutable();
        $entityManager->persist($inProgressGame);
        $entityManager->persist($finishedGame);
        $entityManager->flush();

        $crawler = $client->request('GET', '/');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('in-progress-game', $crawler->text());
        self::assertStringNotContainsString('finished-game', $crawler->text());
        self::assertCount(1, $crawler->filter('a[href="/game/in-progress-game"]'));
    }

    #[Test]
    public function homePageShowsAnEmptyStateWhenNoGameIsInProgress(): void
    {
        $client = self::createClient();

        $crawler = $client->request('GET', '/');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('No game in progress.', $crawler->text());
    }
}
