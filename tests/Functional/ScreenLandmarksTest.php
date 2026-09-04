<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\State\Game;
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

final class ScreenLandmarksTest extends WebTestCase
{
    private KernelBrowser $client; // @phpstan-ignore property.uninitialized (initialized in setUp)
    private EntityManagerInterface $entityManager; // @phpstan-ignore property.uninitialized (initialized in setUp)

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
    }

    #[Test]
    #[DataProvider('provideEveryScreenIsOneHeaderFollowedByOneMainCases')]
    public function everyScreenIsOneHeaderFollowedByOneMain(string $screen): void
    {
        $crawler = $this->visit($this->pathOf($screen));

        $this->assertResponseIsSuccessful();
        $this->assertCount(1, $crawler->filter('header'));
        $this->assertCount(1, $crawler->filter('main'));
    }

    public static function provideEveryScreenIsOneHeaderFollowedByOneMainCases(): iterable
    {
        yield 'the home page' => ['home'];

        yield 'the creation screen' => ['creation'];

        yield 'the dashboard' => ['dashboard'];

        yield 'the operator board' => ['operator'];

        yield 'the operator orders' => ['orders'];

        yield 'the operator calamities' => ['calamities'];

        yield 'the operator trade' => ['trade'];

        yield 'the operator abilities' => ['abilities'];

        yield 'the point of sale' => ['pos'];

        yield 'the trade cards' => ['trade cards'];

        yield 'the player board' => ['board'];

        yield 'the player shop' => ['shop'];

        yield 'the chronicle' => ['chronicle'];

        yield 'the player saga' => ['saga'];
    }

    #[Test]
    #[DataProvider('provideOnlyTheScreensWithASectionSwitcherEndWithAFooterCases')]
    public function onlyTheScreensWithASectionSwitcherEndWithAFooter(string $screen, int $expectedFooters): void
    {
        $crawler = $this->visit($this->pathOf($screen));

        $this->assertCount($expectedFooters, $crawler->filter('body > footer > menu'));
    }

    public static function provideOnlyTheScreensWithASectionSwitcherEndWithAFooterCases(): iterable
    {
        yield 'the dashboard switches between its sections' => ['dashboard', 1];

        yield 'the chronicle switches between its sections' => ['chronicle', 1];

        yield 'the operator board switches between its sections' => ['operator', 1];

        yield 'the operator orders switches between its sections' => ['orders', 1];

        yield 'an operator section still to be built switches too' => ['calamities', 1];

        yield 'the point of sale is opened from a section, not one of them' => ['pos', 0];

        yield 'the player board is one long page' => ['board', 0];

        yield 'the home page lists games' => ['home', 0];
    }

    private function visit(string $path): Crawler
    {
        return $this->client->request(Request::METHOD_GET, $path);
    }

    private function pathOf(string $screen): string
    {
        return match ($screen) {
            'home' => '/',
            'creation' => '/create',
            'dashboard' => '/game/'.$this->runningGame()->slug,
            'operator' => '/game/'.$this->runningGame()->slug.'/operator/board',
            'orders' => '/game/'.$this->runningGame()->slug.'/operator/orders',
            'calamities' => '/game/'.$this->runningGame()->slug.'/operator/calamities',
            'trade' => '/game/'.$this->runningGame()->slug.'/operator/trade',
            'abilities' => '/game/'.$this->runningGame()->slug.'/operator/abilities',
            'pos' => '/game/'.$this->runningGame()->slug.'/operator/pos',
            'trade cards' => '/game/'.$this->runningGame()->slug.'/trade-cards',
            'board' => $this->playerPathOf($this->runningGame()),
            'shop' => $this->playerPathOf($this->runningGame()).'/shop',
            'chronicle' => '/game/'.$this->finishedGame()->slug,
            'saga' => $this->playerPathOf($this->finishedGame()),
        };
    }

    private function runningGame(): Game
    {
        return Tables::westTable($this->entityManager);
    }

    private function finishedGame(): Game
    {
        return GameBuilder::create()->finished()->persist($this->entityManager);
    }

    private function playerPathOf(Game $game): string
    {
        $player = $game->finished
            ? PlayerBuilder::named('Alice')->in($game)->withEmpire('minoa')->persist($this->entityManager)
            : Tables::seat($game, 'Alice');

        return '/game/'.$game->slug.'/player/'.$player->slug;
    }
}
