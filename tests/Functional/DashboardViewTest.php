<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\State\ASTVersion;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use App\Tests\Support\Fixture\GameBuilder;
use App\Tests\Support\Fixture\Tables;
use App\Tests\Support\Fixture\PlayerBuilder;

final class DashboardViewTest extends WebTestCase
{
    #[Test]
    public function dashboardContainsAstTableAndRequirementsSection(): void
    {
        $client = self::createClient();
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);

        $game = Tables::westTable($entityManager);

        $crawler = $client->request(Request::METHOD_GET, '/'.$game->slug);

        $this->assertResponseIsSuccessful();
        $this->assertGreaterThan(0, $crawler->filter('table.ast')->count(), 'AST table should be present');
        $this->assertGreaterThan(0, $crawler->filter('section h3')->count(), 'Requirements section header should be present');
        $this->assertStringContainsString('Archaeological Succession Timeline', $crawler->filter('main')->html());
    }

    #[Test]
    public function astListsPlayersRankedByScore(): void
    {
        $client = self::createClient();
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);

        $game = GameBuilder::create()->build();
        PlayerBuilder::named('Bob')->in($game)->withEmpire('hellas')->withCities(2)->persist($entityManager);
        PlayerBuilder::named('Alice')->in($game)->withEmpire('minoa')->withCities(7)->persist($entityManager);
        PlayerBuilder::named('Kangoo')->in($game)->withEmpire('hatti')->withCities(4)->persist($entityManager);

        $crawler = $client->request(Request::METHOD_GET, '/'.$game->slug);

        $this->assertResponseIsSuccessful();
        $order = $crawler->filter('table.ast tbody tr[data-empire]')->each(static fn ($node) => $node->attr('data-empire'));
        $this->assertSame(['minoa', 'hatti', 'hellas'], $order);
    }

    #[Test]
    #[DataProvider('provideCaptionNamesTheGamesAstVersionAndTurnCases')]
    public function captionNamesTheGamesAstVersionAndTurn(ASTVersion $astVersion, string $expectedCaption): void
    {
        $client = self::createClient();
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);

        $game = GameBuilder::create()->withAstVersion($astVersion)->withCurrentTurn(7)->persist($entityManager);

        $crawler = $client->request(Request::METHOD_GET, '/'.$game->slug);

        $this->assertResponseIsSuccessful();
        $this->assertSame($expectedCaption, trim($crawler->filter('table.ast caption')->text()));
    }

    /** @return iterable<string, array{ASTVersion, string}> */
    public static function provideCaptionNamesTheGamesAstVersionAndTurnCases(): iterable
    {
        yield 'basic is the default version' => [ASTVersion::BASIC, 'Basic version — Turn 7'];

        yield 'expert game' => [ASTVersion::EXPERT, 'Expert version — Turn 7'];
    }

    #[Test]
    public function unknownGameSlugReturnsNotFound(): void
    {
        $client = self::createClient();
        $client->request(Request::METHOD_GET, '/does-not-exist');

        $this->assertResponseStatusCodeSame(404);
    }

}
