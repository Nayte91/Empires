<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\State\Game;
use App\State\Player;
use App\Tests\Support\Fixture\GameBuilder;
use App\Tests\Support\Fixture\PlayerBuilder;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\Request;

/**
 * One titling system, every screen. What each atom does with its props is pinned in
 * Component\PageTitleTest; what is pinned here is the wiring — which screen hands which words to
 * which atom — because that is the half a green atom cannot vouch for.
 *
 * The rule the system reads by, and the reason the shop is titled "Alice" and the trade cards page
 * with the game's slug: a page title names the thing the reader is looking at, never the section
 * they are looking at it in.
 *
 * Four components share the h1 rank, and share the #page-title anchor with it. Each signs its own
 * output with data-title, so which one serves a screen is read off that signature rather than
 * guessed from the shape. Every screen asserts that exactly one of them answers — the anchor is
 * unique and load-bearing, so a screen embedding two titles would still satisfy every text
 * assertion here.
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

    /** Every screen serving a running game is titled with the game itself, by the plain title. */
    #[Test]
    #[DataProvider('provideAScreenOfARunningGameIsTitledWithTheGameAndItsTurnCases')]
    public function aScreenOfARunningGameIsTitledWithTheGameAndItsTurn(string $pathSuffix): void
    {
        $game = $this->createGame();

        $crawler = $this->visit('/'.$game->slug.$pathSuffix);

        $this->assertResponseIsSuccessful();
        $this->assertCount(1, $crawler->filter('#page-title'));
        $this->assertCount(1, $crawler->filter('header#page-title[data-title="page"] > hgroup'));
        $this->assertCount(0, $crawler->filter('#page-title[data-title="celebration"]'));
        $this->assertCount(0, $crawler->filter('#page-title button'));
        $this->assertSame($game->slug, trim($crawler->filter('#page-title h1')->text()));
        $this->assertSame('Turn 4', trim($crawler->filter('#page-title p')->text()));
    }

    public static function provideAScreenOfARunningGameIsTitledWithTheGameAndItsTurnCases(): iterable
    {
        yield 'the dashboard' => [''];

        yield 'the operator console' => ['/operator'];

        yield 'the trade cards' => ['/trade-cards'];
    }

    /**
     * Both of a player's live screens wear the same heading, the shop included — it used to name
     * the kiosk rather than the person sitting at it.
     */
    #[Test]
    #[DataProvider('provideALiveScreenOfAPlayerIsTitledWithTheirNameAndEmpireCases')]
    public function aLiveScreenOfAPlayerIsTitledWithTheirNameAndEmpire(string $pathSuffix): void
    {
        $player = $this->createPlayer();

        $crawler = $this->visit($this->pathOf($player).$pathSuffix);

        $this->assertResponseIsSuccessful();
        $this->assertCount(1, $crawler->filter('#page-title'));
        $this->assertCount(1, $crawler->filter('header#page-title[data-title="player"] > hgroup'));
        $this->assertCount(0, $crawler->filter('#page-title[data-title="celebration"]'));
        $this->assertSame('Alice', trim($crawler->filter('#page-title h1')->text()));
        $this->assertSame('Minoa · Turn 4', trim($crawler->filter('#page-title hgroup p')->text()));
        $this->assertCount(1, $crawler->filter('#page-title button[commandfor="rename-player-'.$player->id.'"]'));
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

    /** The one screen with nothing to qualify: no empty line, no stray paragraph, no qualifier at all. */
    #[Test]
    public function theCreationScreenIsTitledWithoutAnyQualifier(): void
    {
        $crawler = $this->visit('/create');

        $this->assertSame('Create a game', trim($crawler->filter('#page-title h1')->text()));
        $this->assertCount(0, $crawler->filter('#page-title p'));
    }

    /** A game that is over is celebrated rather than merely titled, and carries the mark that says so. */
    #[Test]
    public function theChronicleOfAFinishedGameIsCelebrated(): void
    {
        $game = $this->createGame(finished: true);

        $crawler = $this->visit('/'.$game->slug);

        $this->assertCount(1, $crawler->filter('header#page-title[data-title="celebration"]'));
        $this->assertCount(1, $crawler->filter('#page-title'));
        $this->assertSame($game->slug, trim($crawler->filter('#page-title h1')->text()));
        $this->assertSame('Basic version — Turn 4 — Finished', trim($crawler->filter('#page-title p')->text()));
    }

    /**
     * The saga celebrates the same way the chronicle does, and loses the rename trigger with it: a
     * name cannot be changed once the game that earned it is over.
     */
    #[Test]
    public function theSagaOfAFinishedPlayerIsCelebratedAndOffersNoRename(): void
    {
        $player = $this->createPlayer(finished: true);

        $crawler = $this->visit($this->pathOf($player));

        $this->assertCount(1, $crawler->filter('header#page-title[data-title="celebration"]'));
        $this->assertCount(1, $crawler->filter('#page-title'));
        $this->assertSame('Alice', trim($crawler->filter('#page-title h1')->text()));
        $this->assertSame('Minoa', trim($crawler->filter('#page-title p')->text()));
        $this->assertCount(0, $crawler->filter('#page-title button'));
    }

    /** The console is the one screen still answering for a finished game, so its qualifier says so. */
    #[Test]
    public function theOperatorConsoleMarksAFinishedGameInItsQualifier(): void
    {
        $running = $this->createGame();
        $finished = $this->createGame(finished: true);

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

    private function createGame(bool $finished = false): Game
    {
        $game = GameBuilder::create()->withCurrentTurn(4)->build();

        if ($finished) {
            $game->finishedAt = new \DateTimeImmutable();
        }

        $this->entityManager->persist($game);
        $this->entityManager->flush();

        return $game;
    }

    private function createPlayer(bool $finished = false): Player
    {
        return PlayerBuilder::named('Alice')
            ->withEmpire('minoa')
            ->in($this->createGame($finished))
            ->persist($this->entityManager)
        ;
    }
}
