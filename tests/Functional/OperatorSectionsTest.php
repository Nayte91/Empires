<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\State\Game;
use App\Tests\Support\Fixture\Tables;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\Request;

final class OperatorSectionsTest extends WebTestCase
{
    private KernelBrowser $client; // @phpstan-ignore property.uninitialized (initialized in setUp)
    private EntityManagerInterface $entityManager; // @phpstan-ignore property.uninitialized (initialized in setUp)

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
    }

    #[Test]
    public function aSectionOffersTheWayToAllTheOthers(): void
    {
        $game = $this->game();

        $crawler = $this->visitSection($game, '/board');

        $this->assertResponseIsSuccessful();
        $this->assertEqualsCanonicalizing(
            $this->sectionPathsOf($game),
            $crawler->filter('body > footer > menu > li > a')->each(
                static fn (Crawler $link): string => (string) $link->attr('href'),
            ),
        );
    }

    #[Test]
    #[DataProvider('provideTheVisitedSectionIsTheOnlyOneMarkedAsCurrentCases')]
    public function theVisitedSectionIsTheOnlyOneMarkedAsCurrent(string $pathSuffix): void
    {
        $game = $this->game();

        $crawler = $this->visitSection($game, $pathSuffix);

        $current = $crawler->filter('body > footer > menu a[aria-current="page"]');

        $this->assertCount(1, $current);
        $this->assertSame('/'.$game->slug.'/operator'.$pathSuffix, $current->attr('href'));
    }

    /** @return iterable<string, array{string}> */
    public static function provideTheVisitedSectionIsTheOnlyOneMarkedAsCurrentCases(): iterable
    {
        yield 'the board' => ['/board'];

        yield 'the orders' => ['/orders'];

        yield 'the calamities' => ['/calamities'];

        yield 'the trade' => ['/trade'];

        yield 'the abilities' => ['/abilities'];
    }

    #[Test]
    public function theOperatorBarNavigatesByLinkWhereTheDashboardBarSwitchesByRadio(): void
    {
        $game = $this->game();

        $crawler = $this->visitSection($game, '/board');

        $this->assertCount(5, $crawler->filter('body > footer > menu > li'));
        $this->assertCount(0, $crawler->filter('body > footer input'));
    }

    #[Test]
    public function thePointOfSaleReturnsToTheOrdersSection(): void
    {
        $game = $this->game();

        $crawler = $this->visitSection($game, '/pos');

        $this->assertSame('/'.$game->slug.'/operator/orders', $crawler->filter('#page-title > a')->attr('href'));
    }

    private function visitSection(Game $game, string $pathSuffix): Crawler
    {
        return $this->client->request(Request::METHOD_GET, '/'.$game->slug.'/operator'.$pathSuffix);
    }

    /** @return list<string> */
    private function sectionPathsOf(Game $game): array
    {
        return array_map(
            static fn (string $suffix): string => '/'.$game->slug.'/operator'.$suffix,
            ['/board', '/orders', '/calamities', '/trade', '/abilities'],
        );
    }

    private function game(): Game
    {
        return Tables::westTable($this->entityManager);
    }
}
