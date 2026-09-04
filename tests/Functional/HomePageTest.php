<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Tests\Support\Fixture\GameBuilder;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;

final class HomePageTest extends WebTestCase
{
    #[Test]
    public function homePageListsGamesInProgressButNotFinishedOnes(): void
    {
        $client = self::createClient();
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);

        GameBuilder::create()->withSlug('in-progress-game')->persist($entityManager);
        GameBuilder::create()->withSlug('finished-game')->finished()->persist($entityManager);

        $crawler = $client->request(Request::METHOD_GET, '/');

        $this->assertResponseIsSuccessful();
        $this->assertCount(1, $crawler->filter('a[href="/game/in-progress-game"]'));
        $this->assertCount(0, $crawler->filter('a[href="/game/finished-game"]'));
    }
}
