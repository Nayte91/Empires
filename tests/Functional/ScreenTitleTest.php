<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\State\Player;
use App\Tests\Support\Fixture\GameBuilder;
use App\Tests\Support\Fixture\PlayerBuilder;
use App\Tests\Support\Fixture\Tables;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\Request;

final class ScreenTitleTest extends WebTestCase
{
    private KernelBrowser $client; // @phpstan-ignore property.uninitialized (initialized in setUp)
    private EntityManagerInterface $entityManager; // @phpstan-ignore property.uninitialized (initialized in setUp)

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
    }

    #[Test]
    #[DataProvider('provideAScreenOfARunningGameCarriesExactlyOneTitleCases')]
    public function aScreenOfARunningGameCarriesExactlyOneTitle(string $pathSuffix): void
    {
        $game = GameBuilder::create()->withCurrentTurn(4)->persist($this->entityManager);

        $crawler = $this->visit('/game/'.$game->slug.$pathSuffix);

        $this->assertResponseIsSuccessful();
        $this->assertCount(1, $crawler->filter('#page-title'));
    }

    public static function provideAScreenOfARunningGameCarriesExactlyOneTitleCases(): iterable
    {
        yield 'the dashboard' => [''];
        yield 'the operator board' => ['/operator/board'];
        yield 'the operator orders' => ['/operator/orders'];
        yield 'the operator calamities' => ['/operator/calamities'];
        yield 'the operator trade' => ['/operator/trade'];
        yield 'the operator abilities' => ['/operator/abilities'];
        yield 'the point of sale' => ['/operator/pos'];
        yield 'the trade cards' => ['/trade-cards'];
    }

    #[Test]
    #[DataProvider('provideALiveScreenOfAPlayerCarriesExactlyOneTitleCases')]
    public function aLiveScreenOfAPlayerCarriesExactlyOneTitle(string $pathSuffix): void
    {
        $player = Tables::seat(Tables::westTable($this->entityManager), 'Alice');

        $crawler = $this->visit($this->pathOf($player).$pathSuffix);

        $this->assertResponseIsSuccessful();
        $this->assertCount(1, $crawler->filter('#page-title'));
    }

    public static function provideALiveScreenOfAPlayerCarriesExactlyOneTitleCases(): iterable
    {
        yield 'the board' => [''];
        yield 'the shop' => ['/shop'];
    }

    #[Test]
    public function theChronicleOfAFinishedGameCarriesExactlyOneTitle(): void
    {
        $game = GameBuilder::create()->withCurrentTurn(4)->finished()->persist($this->entityManager);

        $crawler = $this->visit('/game/'.$game->slug);

        $this->assertCount(1, $crawler->filter('#page-title'));
    }

    #[Test]
    public function theSagaOfAFinishedPlayerCarriesExactlyOneTitle(): void
    {
        $game = GameBuilder::create()->finished()->persist($this->entityManager);
        $player = PlayerBuilder::named('Alice')->in($game)->withEmpire('minoa')->persist($this->entityManager);

        $crawler = $this->visit($this->pathOf($player));

        $this->assertCount(1, $crawler->filter('#page-title'));
    }

    private function visit(string $path): Crawler
    {
        return $this->client->request(Request::METHOD_GET, $path);
    }

    private function pathOf(Player $player): string
    {
        return '/game/'.$player->game->slug.'/player/'.$player->slug;
    }
}
