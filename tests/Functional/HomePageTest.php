<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\State\GameSession;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class HomePageTest extends WebTestCase
{
    #[Test]
    public function homePageIsAccessible(): void
    {
        $client = self::createClient();

        $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/');

        $this->assertResponseIsSuccessful();
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

        $crawler = $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/');

        $this->assertResponseIsSuccessful();
        $this->assertStringContainsString('in-progress-game', $crawler->text());
        $this->assertStringNotContainsString('finished-game', $crawler->text());
        $this->assertCount(1, $crawler->filter('a[href="/in-progress-game"]'));
    }

    #[Test]
    public function homePageShowsAnEmptyStateWhenNoGameIsInProgress(): void
    {
        $client = self::createClient();

        $crawler = $client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/');

        $this->assertResponseIsSuccessful();
        $this->assertStringContainsString('No game in progress.', $crawler->text());
    }
}
