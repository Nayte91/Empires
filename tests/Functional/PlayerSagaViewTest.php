<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\State\Game;
use App\State\Player;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\Request;

/**
 * The player's seat keeps its one address and changes what it answers there, exactly as the game's
 * did when the chronicle landed. The branch is the feature, so both sides are asserted on every
 * question: an assertion that the finished page lacks something proves nothing on its own — it
 * passes just as happily against a page that failed to render, or a selector that never matched
 * anything anywhere.
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

    /** Canary for the running half of the fork: the address still answers with the live, editable board. */
    #[Test]
    public function aPlayerInAGameStillRunningIsServedTheLiveBoard(): void
    {
        $player = $this->createPlayer(finished: false);

        $crawler = $this->visit($player);

        $this->assertResponseIsSuccessful();
        $this->assertGreaterThan(0, $crawler->filter('button[commandfor^="stat-picker-"]')->count());
    }

    /** Canary for the finished half: same address, no redirect, and the chart that only exists here. */
    #[Test]
    public function aFinishedGameServesTheSagaAtTheVerySameAddress(): void
    {
        $player = $this->createPlayer(finished: true);

        $crawler = $this->visit($player);

        $this->assertResponseIsSuccessful();
        $this->assertFalse($this->client->getResponse()->isRedirect());
        $this->assertSame($this->pathOf($player), $this->client->getRequest()->getPathInfo());
        $this->assertCount(1, $crawler->filter('#purchase-value canvas'));
    }

    /**
     * Everything the live board offers to change the game with. None of it has anything left to act
     * on once the game is over, and a control that answers nothing is worse than an absent one.
     */
    #[Test]
    #[DataProvider('provideTheSagaDropsWhatOnlyARunningGameCanActOnCases')]
    public function theSagaDropsWhatOnlyARunningGameCanActOn(string $selector): void
    {
        $running = $this->createPlayer(finished: false);
        $finished = $this->createPlayer(finished: true);

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

    /**
     * The swap the whole page turns on: the same five stats, offered as dialogs to change them while
     * the game runs and as a description list once it cannot. Both halves in one test, because a
     * page that dropped the pickers and grew no counters would satisfy either half alone.
     */
    #[Test]
    public function theSagaTurnsTheBoardsStatPickersIntoReadOnlyCounters(): void
    {
        $running = $this->createPlayer(finished: false);
        $finished = $this->createPlayer(finished: true);

        $onBoard = $this->visit($running);
        $onSaga = $this->visit($finished);

        $this->assertGreaterThan(0, $onBoard->filter('button[commandfor^="stat-picker-"]')->count());
        $this->assertCount(0, $onBoard->filter('[data-stat]'));

        $this->assertCount(0, $onSaga->filter('button[commandfor^="stat-picker-"]'));
        $this->assertCount(5, $onSaga->filter('dl > div[data-stat]'));
    }

    /** What was bought is the record of the game that was played, so it survives the game ending. */
    #[Test]
    public function theSagaKeepsTheOwnedAdvancesTheBoardShowed(): void
    {
        $running = $this->createPlayer(finished: false);
        $finished = $this->createPlayer(finished: true);

        $this->assertCount(2, $this->visit($running)->filter('img[id^="product-"]'));
        $this->assertCount(2, $this->visit($finished)->filter('img[id^="product-"]'));
    }

    /** The final standing is the one thing the seat gains rather than loses: the board never carried it. */
    #[Test]
    public function theSagaCarriesTheAstBoardTheLivePlayerBoardNeverDid(): void
    {
        $running = $this->createPlayer(finished: false);
        $finished = $this->createPlayer(finished: true);

        $this->assertCount(0, $this->visit($running)->filter('caption#ast'));
        $this->assertCount(1, $this->visit($finished)->filter('caption#ast'));
    }

    private function visit(Player $player): Crawler
    {
        return $this->client->request(Request::METHOD_GET, $this->pathOf($player));
    }

    private function pathOf(Player $player): string
    {
        return '/'.$player->game->slug.'/player/'.$player->slug;
    }

    /** Two advances, so "keeps the advances" is a count a broken grid cannot reach by accident. */
    private function createPlayer(bool $finished): Player
    {
        $game = new Game();
        $game->currentTurn = 4;
        $player = new Player($game, 'Alice', 'minoa');
        $player->ownAdvances(['pottery', 'agriculture']);

        if ($finished) {
            $game->finishedAt = new \DateTimeImmutable();
        }

        $this->entityManager->persist($game);
        $this->entityManager->flush();

        return $player;
    }
}
