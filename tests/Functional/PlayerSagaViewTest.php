<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\State\Player;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\Request;
use App\Tests\Support\Fixture\GameBuilder;
use App\Tests\Support\Fixture\PlayerBuilder;
use App\Tests\Support\Fixture\Tables;

/**
 * An assertion that the finished page lacks something passes just as happily against a page that
 * failed to render — so both sides are asserted on every question.
 */
final class PlayerSagaViewTest extends WebTestCase
{
    private KernelBrowser $client; // @phpstan-ignore property.uninitialized (initialized in setUp)
    private EntityManagerInterface $entityManager; // @phpstan-ignore property.uninitialized (initialized in setUp)

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
    }

    #[Test]
    public function aPlayerInAGameStillRunningIsServedTheLiveBoard(): void
    {
        $player = Tables::seat(Tables::westTable($this->entityManager), 'Alice');

        $crawler = $this->visit($player);

        $this->assertResponseIsSuccessful();
        $this->assertGreaterThan(0, $crawler->filter('button[commandfor^="stat-picker-"]')->count());
    }

    #[Test]
    public function aFinishedGameServesTheSagaAtTheVerySameAddress(): void
    {
        $player = $this->aliceInAFinishedGame();

        $crawler = $this->visit($player);

        $this->assertResponseIsSuccessful();
        $this->assertFalse($this->client->getResponse()->isRedirect());
        $this->assertSame($this->pathOf($player), $this->client->getRequest()->getPathInfo());
        $this->assertCount(1, $crawler->filter('#purchase-value canvas'));
    }

    #[Test]
    #[DataProvider('provideTheSagaDropsWhatOnlyARunningGameCanActOnCases')]
    public function theSagaDropsWhatOnlyARunningGameCanActOn(string $selector): void
    {
        $running = Tables::seat(Tables::westTable($this->entityManager), 'Alice');

        $finished = $this->aliceInAFinishedGame();

        $onBoard = $this->visit($running)->filter($selector)->count();
        $onSaga = $this->visit($finished)->filter($selector)->count();

        $this->assertGreaterThan(0, $onBoard);
        $this->assertSame(0, $onSaga);
    }

    public static function provideTheSagaDropsWhatOnlyARunningGameCanActOnCases(): iterable
    {
        yield 'the way into the shop' => ['a[href$="/shop"]'];

        yield 'the rename dialog' => ['dialog[id^="rename-player-"]'];

        yield 'the outlook, which advises on a game still to be played' => ['ul[role="status"]'];

        yield 'the discounts, which price a catalogue nobody can buy from any more' => ['tr[data-advance-category]'];
    }

    /** Both halves in one test: a page that dropped the pickers and grew no counters satisfies either alone. */
    #[Test]
    public function theSagaTurnsTheBoardsStatPickersIntoReadOnlyCounters(): void
    {
        $running = Tables::seat(Tables::westTable($this->entityManager), 'Alice');

        $finished = $this->aliceInAFinishedGame();

        $onBoard = $this->visit($running);
        $onSaga = $this->visit($finished);

        $this->assertGreaterThan(0, $onBoard->filter('button[commandfor^="stat-picker-"]')->count());
        $this->assertCount(0, $onBoard->filter('[data-stat]'));

        $this->assertCount(0, $onSaga->filter('button[commandfor^="stat-picker-"]'));
        $this->assertCount(5, $onSaga->filter('dl > div[data-stat]'));
    }

    #[Test]
    public function theSagaKeepsTheOwnedAdvancesTheBoardShowed(): void
    {
        $running = Tables::seat(Tables::westTable($this->entityManager), 'Alice');

        $finished = $this->aliceInAFinishedGame();

        $this->assertCount(2, $this->visit($running)->filter('img[id^="product-"]'));
        $this->assertCount(2, $this->visit($finished)->filter('img[id^="product-"]'));
    }

    #[Test]
    public function theSagaCarriesTheAstBoardTheLivePlayerBoardNeverDid(): void
    {
        $running = Tables::seat(Tables::westTable($this->entityManager), 'Alice');

        $finished = $this->aliceInAFinishedGame();

        $this->assertCount(0, $this->visit($running)->filter('caption#ast'));
        $this->assertCount(1, $this->visit($finished)->filter('caption#ast'));
    }

    private function aliceInAFinishedGame(): Player
    {
        $game = GameBuilder::create()->finished()->persist($this->entityManager);

        return PlayerBuilder::named('Alice')->in($game)
            ->withEmpire('minoa')
            ->withAdvances(['pottery', 'agriculture'])
            ->persist($this->entityManager)
        ;
    }

    private function visit(Player $player): Crawler
    {
        return $this->client->request(Request::METHOD_GET, $this->pathOf($player));
    }

    private function pathOf(Player $player): string
    {
        return '/'.$player->game->slug.'/player/'.$player->slug;
    }
}
