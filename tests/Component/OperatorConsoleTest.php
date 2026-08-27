<?php

declare(strict_types=1);

namespace App\Tests\Component;

use App\State\Game;
use App\State\Player;
use App\Tests\Support\Fixture\GameBuilder;
use App\Tests\Support\Fixture\PlayerBuilder;
use App\Tests\Support\GameFixtureTrait;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\UX\LiveComponent\Test\InteractsWithLiveComponents;

final class OperatorConsoleTest extends WebTestCase
{
    use GameFixtureTrait;
    use InteractsWithLiveComponents;

    #[Test]
    public function theConsoleListensOnItsOwnTopic(): void
    {
        $game = GameBuilder::create()->persist($this->entityManager);

        $rendered = $this->createLiveComponent('OperatorConsole', ['game' => $game])->render()->crawler();

        $this->assertSame(
            'empires/game/'.$game->id.'/operator',
            $rendered->filter('[data-mercure-refresh-topic-value]')->attr('data-mercure-refresh-topic-value'),
        );
    }

    #[Test]
    public function rendersTheCurrentTurn(): void
    {
        $game = GameBuilder::create()->persist($this->entityManager);

        $rendered = $this->createLiveComponent('OperatorConsole', ['game' => $game])->render();

        $this->assertStringContainsString('Turn 1', $rendered->toString());
    }

    #[Test]
    #[DataProvider('providePreviousTurnButtonIsDisabledOnlyOnTheFirstTurnCases')]
    public function previousTurnButtonIsDisabledOnlyOnTheFirstTurn(int $currentTurn, bool $expectedDisabled): void
    {
        $game = GameBuilder::create()->persist($this->entityManager);
        $game->currentTurn = $currentTurn;
        $this->entityManager->flush();

        $rendered = $this->createLiveComponent('OperatorConsole', ['game' => $game])->render();

        $button = $rendered->crawler()->filter('button')->reduce(
            static fn ($node): bool => str_contains((string) $node->text(), 'Previous turn'),
        );

        $this->assertSame($expectedDisabled, null !== $button->attr('disabled'));
    }

    /** @return iterable<string, array{int, bool}> */
    public static function providePreviousTurnButtonIsDisabledOnlyOnTheFirstTurnCases(): iterable
    {
        yield 'first turn has nothing to go back to' => [1, true];

        yield 'second turn can go back' => [2, false];
    }

    #[Test]
    public function rendersOneDetailsTabPerPlayerPlusGeneralSharingTheSameGroupWithGeneralOpenByDefault(): void
    {
        $game = GameBuilder::create()->persist($this->entityManager);
        PlayerBuilder::named('Alice')->in($game)->withEmpire('minoa')->persist($this->entityManager);
        PlayerBuilder::named('Bob')->in($game)->withEmpire('minoa')->persist($this->entityManager);

        $rendered = $this->createLiveComponent('OperatorConsole', ['game' => $game])->render();

        $details = $rendered->crawler()->filter('details[name="operator-tabs"]');

        $this->assertCount(3, $details);

        $names = [];
        foreach ($details as $node) {
            $names[] = $node->getAttribute('name');
        }
        $this->assertSame(['operator-tabs'], array_unique($names));

        $generalDetails = $details->reduce(
            static fn ($node): bool => 'General' === trim((string) $node->filter('summary')->text()),
        );
        $this->assertNotNull($generalDetails->attr('open'));
    }

    #[Test]
    public function generalPanelContainsTheTurnButtons(): void
    {
        $game = GameBuilder::create()->persist($this->entityManager);

        $rendered = $this->createLiveComponent('OperatorConsole', ['game' => $game])->render();

        $generalDetails = $rendered->crawler()->filter('details')->reduce(
            static fn ($node): bool => 'General' === trim((string) $node->filter('summary')->text()),
        );

        $buttonTexts = $generalDetails->filter('button')->each(
            static fn ($node): string => trim($node->text()),
        );

        $this->assertContains('« Previous turn', $buttonTexts);
        $this->assertContains('Next turn »', $buttonTexts);
        $this->assertContains('Finish game', $buttonTexts);
    }

    #[Test]
    public function theGeneralPanelOffersAControlBoardRowForEachPlayerAlongsideTheTurnControls(): void
    {
        $game = GameBuilder::create()->persist($this->entityManager);
        PlayerBuilder::named('Alice')->in($game)->withEmpire('minoa')->persist($this->entityManager);
        PlayerBuilder::named('Bob')->in($game)->withEmpire('rome')->persist($this->entityManager);

        $rendered = $this->createLiveComponent('OperatorConsole', ['game' => $game])->render();

        $generalPanel = $rendered->crawler()->filter('details[data-tab-id="general"]');

        // Three stat pickers per player — the tracking stats only; which three is pinned by
        // theGeneralOverviewOffersOnlyTheTrackingStatsWhileThePlayerTabKeepsThemAll.
        $this->assertCount(6, $generalPanel->filter('button[command="show-modal"]'));
    }

    /** The regression guard for the finished-game behaviour: a finished game offers no editing at all. */
    #[Test]
    public function aFinishedGameOffersNoControlBoardRow(): void
    {
        $game = GameBuilder::create()->persist($this->entityManager);
        PlayerBuilder::named('Alice')->in($game)->withEmpire('minoa')->persist($this->entityManager);
        $game->finishedAt = new \DateTimeImmutable();
        $this->entityManager->flush();

        $rendered = $this->createLiveComponent('OperatorConsole', ['game' => $game])->render();

        $generalPanel = $rendered->crawler()->filter('details[data-tab-id="general"]');

        $this->assertCount(0, $generalPanel->filter('button[command="show-modal"]'));
    }

    #[Test]
    public function aPlayerTabPanelContainsTheSixStatTriggerButtons(): void
    {
        $game = GameBuilder::create()->persist($this->entityManager);
        $player = PlayerBuilder::named('Alice')->in($game)->withEmpire('minoa')->persist($this->entityManager);

        $rendered = $this->createLiveComponent('OperatorConsole', ['game' => $game])->render();

        $playerDetails = $rendered->crawler()->filter("details[data-tab-id=\"{$player->id}\"]");

        $this->assertCount(6, $playerDetails->filter('button[command="show-modal"]'));
    }

    /**
     * The console shares the player board's ControlBoard, but the operator drives every player
     * from one screen — a link into a single player's shop belongs to that player's own board.
     */
    #[Test]
    public function aPlayerTabPanelOffersNoShopLink(): void
    {
        $game = GameBuilder::create()->persist($this->entityManager);
        $player = PlayerBuilder::named('Alice')->in($game)->withEmpire('minoa')->persist($this->entityManager);

        $rendered = $this->createLiveComponent('OperatorConsole', ['game' => $game])->render();

        $playerDetails = $rendered->crawler()->filter("details[data-tab-id=\"{$player->id}\"]");

        $this->assertCount(0, $playerDetails->filter('a[href$="/shop"]'));
    }

    /**
     * Advisories left the control board for the Outlook block, which the console does not carry:
     * the operator screen is deliberately operational only, counters and commands.
     */
    #[Test]
    public function aPlayerTabPanelCarriesNoAdvisory(): void
    {
        $game = GameBuilder::create()->persist($this->entityManager);
        $player = PlayerBuilder::named('Alice')->in($game)->withEmpire('minoa')->persist($this->entityManager);
        $player->cities = 3;
        $player->census = 2;
        $this->entityManager->flush();

        $rendered = $this->createLiveComponent('OperatorConsole', ['game' => $game])->render();

        $playerDetails = $rendered->crawler()->filter("details[data-tab-id=\"{$player->id}\"]");

        $this->assertSame([], array_filter(
            $playerDetails->filter('li')->each(static fn ($node): string => trim($node->text())),
            static fn (string $text): bool => str_contains($text, "You can't"),
        ));
    }

    #[Test]
    public function finishGameFillsInFinishedAt(): void
    {
        $game = GameBuilder::create()->persist($this->entityManager);

        $this->createLiveComponent('OperatorConsole', ['game' => $game])->call('finishGame');

        $this->assertInstanceOf(\DateTimeImmutable::class, $this->reloadGame($game)->finishedAt);
    }

    #[Test]
    #[DataProvider('provideNextTurnAdvancesTheGameUpToTheTwentiethTurnCases')]
    public function nextTurnAdvancesTheGameUpToTheTwentiethTurn(int $startingTurn, int $expectedTurn): void
    {
        $game = GameBuilder::create()->persist($this->entityManager);
        $game->currentTurn = $startingTurn;
        $this->entityManager->flush();

        $this->createLiveComponent('OperatorConsole', ['game' => $game])->call('nextTurn');

        $this->assertSame($expectedTurn, $this->reloadGame($game)->currentTurn);
    }

    /** @return iterable<string, array{int, int}> */
    public static function provideNextTurnAdvancesTheGameUpToTheTwentiethTurnCases(): iterable
    {
        yield 'a turn in the middle of the game advances by one' => [1, 2];

        yield 'the twentieth turn is the last, and clamps' => [20, 20];
    }

    #[Test]
    public function previousTurnNeverGoesBelowTheFirstTurn(): void
    {
        $game = GameBuilder::create()->persist($this->entityManager);

        $this->createLiveComponent('OperatorConsole', ['game' => $game])->call('previousTurn');

        $this->assertSame(1, $this->reloadGame($game)->currentTurn);
    }

    #[Test]
    public function nextTurnOnAFinishedGameDoesNotChangeTheCurrentTurn(): void
    {
        $game = GameBuilder::create()->persist($this->entityManager);
        $game->finishedAt = new \DateTimeImmutable();
        $this->entityManager->flush();

        $this->createLiveComponent('OperatorConsole', ['game' => $game])->call('nextTurn');

        $this->assertSame(1, $this->reloadGame($game)->currentTurn);
    }

    #[Test]
    public function aPlayerTabPanelContainsItsOrderCardsRegionWithAnEmptyCardForTheCurrentTurn(): void
    {
        $game = GameBuilder::create()->persist($this->entityManager);
        $player = PlayerBuilder::named('Alice')->in($game)->withEmpire('minoa')->persist($this->entityManager);

        $rendered = $this->createLiveComponent('OperatorConsole', ['game' => $game])->render();

        $playerDetails = $rendered->crawler()->filter("details[data-tab-id=\"{$player->id}\"]");

        $card = $playerDetails->filter('article')->reduce(
            static fn ($node): bool => str_contains((string) $node->text(), 'Turn 1'),
        );

        $this->assertStringContainsString('Empty', $card->text());
    }

    #[Test]
    public function ordersStampForChangesWhenTheCurrentTurnChangesEvenWithZeroOrders(): void
    {
        $game = GameBuilder::create()->persist($this->entityManager);
        $player = PlayerBuilder::named('Alice')->in($game)->withEmpire('minoa')->persist($this->entityManager);

        $console = $this->createLiveComponent('OperatorConsole', ['game' => $game])->component();
        $stampAtTurnOne = $console->ordersStampFor($player);

        $game->currentTurn = 2;
        $this->entityManager->flush();

        $stampAtTurnTwo = $console->ordersStampFor($this->reloadPlayer($player));

        $this->assertNotSame($stampAtTurnOne, $stampAtTurnTwo);
    }

    /**
     * The two lists are intentionally different, and nothing else guards them against silently
     * converging back: the General tab tracks how far along each empire is, economy management
     * belongs to the player's own tab.
     */
    #[Test]
    public function theGeneralOverviewOffersOnlyTheTrackingStatsWhileThePlayerTabKeepsThemAll(): void
    {
        $game = GameBuilder::create()->persist($this->entityManager);
        $player = PlayerBuilder::named('Alice')->in($game)->withEmpire('minoa')->persist($this->entityManager);

        $crawler = $this->createLiveComponent('OperatorConsole', ['game' => $game])->render()->crawler();

        $this->assertSame(
            ['cities', 'astPosition', 'census'],
            $this->statsOffered($crawler->filter("[data-player-id=\"{$player->id}\"]")),
        );

        $this->assertSame(
            ['cities', 'astPosition', 'census', 'treasury', 'ships', 'cards'],
            $this->statsOffered($crawler->filter("details[data-tab-id=\"{$player->id}\"]")),
        );
    }

    /**
     * The overview and the player tabs both render a picker for the same player and stat, and
     * `commandfor` resolves by id. A duplicate opens whichever dialog comes first in the document —
     * which the exclusive `<details name>` accordion has just hidden, so the modal opens at 0×0 and
     * the button reads as dead. Uniqueness is the only thing that keeps them apart.
     */
    #[Test]
    public function theConsoleRendersNoDuplicatedElementId(): void
    {
        $game = GameBuilder::create()->persist($this->entityManager);
        PlayerBuilder::named('Alice')->in($game)->withEmpire('minoa')->persist($this->entityManager);
        PlayerBuilder::named('Bob')->in($game)->withEmpire('rome')->persist($this->entityManager);

        $crawler = $this->createLiveComponent('OperatorConsole', ['game' => $game])->render()->crawler();

        $ids = $crawler->filter('[id]')->each(static fn (Crawler $node): string => (string) $node->attr('id'));

        $this->assertSame([], array_values(array_unique(array_diff_assoc($ids, array_unique($ids)))));
    }

    /** @return list<string> */
    private function statsOffered(Crawler $scope): array
    {
        return $scope->filter('dialog[id^="stat-picker-"]')->each(
            static fn (Crawler $picker): string => explode('-', (string) $picker->attr('id'))[2],
        );
    }

    private function freshEntityManager(): EntityManagerInterface
    {
        return self::getContainer()->get(EntityManagerInterface::class);
    }

    private function reloadGame(Game $game): Game
    {
        $reloaded = $this->freshEntityManager()->find(Game::class, $game->id);
        $this->assertInstanceOf(Game::class, $reloaded);

        return $reloaded;
    }

    private function reloadPlayer(Player $player): Player
    {
        $reloaded = $this->freshEntityManager()->find(Player::class, $player->id);
        $this->assertInstanceOf(Player::class, $reloaded);

        return $reloaded;
    }
}
