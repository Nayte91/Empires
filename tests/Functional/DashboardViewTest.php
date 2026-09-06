<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Tests\Support\Fixture\GameBuilder;
use App\Tests\Support\Fixture\Tables;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;

final class DashboardViewTest extends WebTestCase
{
    #[Test]
    public function theDashboardCarriesTheAstBoardAndItsRequirements(): void
    {
        $client = self::createClient();
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);

        $game = Tables::westTable($entityManager);

        $crawler = $client->request(Request::METHOD_GET, '/game/'.$game->slug);

        $this->assertResponseIsSuccessful();
        $this->assertCount(1, $crawler->filter('caption#ast'));
        $this->assertGreaterThan(0, $crawler->filter('section h3')->count());
    }

    #[Test]
    #[DataProvider('provideTheNavigationFlagsTheOperatorEntryOfALockedGameCases')]
    public function theNavigationFlagsTheOperatorEntryOfALockedGame(?string $operatorPin, int $expectedFlags): void
    {
        $client = self::createClient();
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $builder = GameBuilder::create();

        if (null !== $operatorPin) {
            $builder = $builder->withOperatorPin($operatorPin);
        }

        $game = $builder->persist($entityManager);

        $crawler = $client->request(Request::METHOD_GET, '/game/'.$game->slug);

        $this->assertResponseIsSuccessful();
        $this->assertCount($expectedFlags, $crawler->filter('nav li[data-locked]'));
    }

    /** @return iterable<string, array{?string, int}> */
    public static function provideTheNavigationFlagsTheOperatorEntryOfALockedGameCases(): iterable
    {
        yield 'a locked game' => ['4821', 1];

        yield 'an open game' => [null, 0];
    }

    #[Test]
    public function unknownGameSlugReturnsNotFound(): void
    {
        $client = self::createClient();
        $client->request(Request::METHOD_GET, '/game/does-not-exist');

        $this->assertResponseStatusCodeSame(404);
    }
}
