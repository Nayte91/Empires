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

/**
 * Each component signs with data-title, and the #page-title anchor is counted because two titles
 * would satisfy every text assertion here.
 */
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
    #[DataProvider('provideAScreenOfARunningGameIsTitledWithTheGameAndItsTurnCases')]
    public function aScreenOfARunningGameIsTitledWithTheGameAndItsTurn(string $pathSuffix): void
    {
        $game = GameBuilder::create()->withCurrentTurn(4)->persist($this->entityManager);

        $crawler = $this->visit('/'.$game->slug.$pathSuffix);

        $this->assertResponseIsSuccessful();
        $this->assertCount(1, $crawler->filter('#page-title'));
        $this->assertCount(1, $crawler->filter('#page-title[data-title="page"]'));
        $this->assertSame($game->slug, trim($crawler->filter('#page-title h1')->text()));
        $this->assertSame('Turn 4', trim($crawler->filter('#page-title p')->text()));
    }

    public static function provideAScreenOfARunningGameIsTitledWithTheGameAndItsTurnCases(): iterable
    {
        yield 'the dashboard' => [''];

        yield 'the operator console' => ['/operator'];

        yield 'the trade cards' => ['/trade-cards'];
    }

    #[Test]
    #[DataProvider('provideALiveScreenOfAPlayerIsTitledWithTheirNameAndEmpireCases')]
    public function aLiveScreenOfAPlayerIsTitledWithTheirNameAndEmpire(string $pathSuffix): void
    {
        $player = Tables::seat(Tables::westTable($this->entityManager), 'Alice');

        $crawler = $this->visit($this->pathOf($player).$pathSuffix);

        $this->assertResponseIsSuccessful();
        $this->assertCount(1, $crawler->filter('#page-title'));
        $this->assertCount(1, $crawler->filter('#page-title[data-title="player"]'));
        $this->assertSame('Alice', trim($crawler->filter('#page-title h1')->text()));
        $this->assertSame('Minoa · Turn 4', trim($crawler->filter('#page-title p')->text()));
    }

    public static function provideALiveScreenOfAPlayerIsTitledWithTheirNameAndEmpireCases(): iterable
    {
        yield 'the board' => [''];

        yield 'the shop' => ['/shop'];
    }

    #[Test]
    public function theHomePageIsTitledWithTheProductAndWhatItLists(): void
    {
        $crawler = $this->visit('/');

        $this->assertSame('Empires', trim($crawler->filter('#page-title h1')->text()));
        $this->assertSame('Games in progress', trim($crawler->filter('#page-title p')->text()));
    }

    #[Test]
    public function theCreationScreenIsTitledWithoutAnyQualifier(): void
    {
        $crawler = $this->visit('/create');

        $this->assertSame('Create a game', trim($crawler->filter('#page-title h1')->text()));
        $this->assertCount(0, $crawler->filter('#page-title p'));
    }

    #[Test]
    public function theChronicleOfAFinishedGameIsCelebrated(): void
    {
        $game = GameBuilder::create()->withCurrentTurn(4)->finished()->persist($this->entityManager);

        $crawler = $this->visit('/'.$game->slug);

        $this->assertCount(1, $crawler->filter('#page-title'));
        $this->assertCount(1, $crawler->filter('#page-title[data-title="celebration"]'));
        $this->assertSame($game->slug, trim($crawler->filter('#page-title h1')->text()));
        $this->assertSame('Basic version — Turn 4 — Finished', trim($crawler->filter('#page-title p')->text()));
    }

    #[Test]
    public function theSagaOfAFinishedPlayerIsCelebratedAndOffersNoRename(): void
    {
        $game = GameBuilder::create()->finished()->persist($this->entityManager);
        $player = PlayerBuilder::named('Alice')->in($game)->withEmpire('minoa')->persist($this->entityManager);

        $crawler = $this->visit($this->pathOf($player));

        $this->assertCount(1, $crawler->filter('#page-title'));
        $this->assertCount(1, $crawler->filter('#page-title[data-title="celebration"]'));
        $this->assertSame('Alice', trim($crawler->filter('#page-title h1')->text()));
        $this->assertSame('Minoa', trim($crawler->filter('#page-title p')->text()));
        $this->assertCount(0, $crawler->filter('#page-title button'));
    }

    #[Test]
    public function theOperatorConsoleMarksAFinishedGameInItsQualifier(): void
    {
        $running = GameBuilder::create()->withCurrentTurn(4)->persist($this->entityManager);
        $finished = GameBuilder::create()->withCurrentTurn(4)->finished()->persist($this->entityManager);

        $onRunning = $this->visit('/'.$running->slug.'/operator')->filter('#page-title p');
        $onFinished = $this->visit('/'.$finished->slug.'/operator')->filter('#page-title p');

        $this->assertSame('Turn 4', trim($onRunning->text()));
        $this->assertSame('Turn 4 — finished', trim($onFinished->text()));
    }

    private function visit(string $path): Crawler
    {
        return $this->client->request(Request::METHOD_GET, $path);
    }

    private function pathOf(Player $player): string
    {
        return '/'.$player->game->slug.'/player/'.$player->slug;
    }
}
