<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\GameSession;
use App\Entity\Player;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\UX\LiveComponent\Test\InteractsWithLiveComponents;

final class OperatorConsoleTest extends WebTestCase
{
    use InteractsWithLiveComponents;

    private EntityManagerInterface $entityManager; // @phpstan-ignore property.uninitialized (initialized in setUp)

    protected function setUp(): void
    {
        self::bootKernel();

        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
    }

    #[Test]
    public function mercureRefreshHasNoEventFilterSoItReceivesEveryEvent(): void
    {
        $game = $this->createGame();

        $rendered = $this->createLiveComponent('OperatorConsole', ['game' => $game])->render()->toString();

        $this->assertStringNotContainsString('data-mercure-refresh-events-value', $rendered);
    }

    #[Test]
    public function rendersTheCurrentTurn(): void
    {
        $game = $this->createGame();

        $rendered = $this->createLiveComponent('OperatorConsole', ['game' => $game])->render();

        $this->assertStringContainsString('Turn 1', $rendered->toString());
    }

    #[Test]
    public function previousTurnButtonIsDisabledOnTurnOne(): void
    {
        $game = $this->createGame();

        $rendered = $this->createLiveComponent('OperatorConsole', ['game' => $game])->render();

        $button = $rendered->crawler()->filter('button')->reduce(
            static fn ($node): bool => str_contains((string) $node->text(), 'Previous turn'),
        );

        $this->assertNotNull($button->attr('disabled'));
    }

    #[Test]
    public function rendersOneDetailsTabPerPlayerPlusGeneralSharingTheSameGroupWithGeneralOpenByDefault(): void
    {
        $game = $this->createGame();
        $this->createPlayer($game, 'Alice');
        $this->createPlayer($game, 'Bob');

        $rendered = $this->createLiveComponent('OperatorConsole', ['game' => $game])->render();

        $details = $rendered->crawler()->filter('details');

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
        $game = $this->createGame();

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
    public function aPlayerTabPanelContainsTheSixStatTriggerButtons(): void
    {
        $game = $this->createGame();
        $player = $this->createPlayer($game, 'Alice');

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
        $game = $this->createGame();
        $player = $this->createPlayer($game, 'Alice');

        $rendered = $this->createLiveComponent('OperatorConsole', ['game' => $game])->render();

        $playerDetails = $rendered->crawler()->filter("details[data-tab-id=\"{$player->id}\"]");

        $this->assertCount(0, $playerDetails->filter('a[href$="/shop"]'));
    }

    /**
     * The stat block became a shared ControlBoard so the operator gets the advisories too:
     * the game master must see who is in trouble without opening each player board.
     */
    #[Test]
    public function aPlayerTabPanelSurfacesThatPlayersAdvisories(): void
    {
        $game = $this->createGame();
        $player = $this->createPlayer($game, 'Alice');
        $player->cities = 3;
        $player->census = 2;
        $this->entityManager->flush();

        $rendered = $this->createLiveComponent('OperatorConsole', ['game' => $game])->render();

        $playerDetails = $rendered->crawler()->filter("details[data-tab-id=\"{$player->id}\"]");

        $this->assertCount(1, $playerDetails->filter('[role="alert"] li'));
    }

    #[Test]
    public function previousTurnButtonIsEnabledOnTurnTwo(): void
    {
        $game = $this->createGame();
        $game->currentTurn = 2;
        $this->entityManager->flush();

        $rendered = $this->createLiveComponent('OperatorConsole', ['game' => $game])->render();

        $button = $rendered->crawler()->filter('button')->reduce(
            static fn ($node): bool => str_contains((string) $node->text(), 'Previous turn'),
        );

        $this->assertNull($button->attr('disabled'));
    }

    #[Test]
    public function nextTurnIncrementsTheCurrentTurn(): void
    {
        $game = $this->createGame();

        $this->createLiveComponent('OperatorConsole', ['game' => $game])->call('nextTurn');

        $this->assertSame(2, $this->reloadGame($game)->currentTurn);
    }

    #[Test]
    public function nextTurnClampsAtTwenty(): void
    {
        $game = $this->createGame();
        $game->currentTurn = 20;
        $this->entityManager->flush();

        $this->createLiveComponent('OperatorConsole', ['game' => $game])->call('nextTurn');

        $this->assertSame(20, $this->reloadGame($game)->currentTurn);
    }

    #[Test]
    public function finishGameFillsInFinishedAt(): void
    {
        $game = $this->createGame();

        $this->createLiveComponent('OperatorConsole', ['game' => $game])->call('finishGame');

        $this->assertInstanceOf(\DateTimeImmutable::class, $this->reloadGame($game)->finishedAt);
    }

    #[Test]
    public function nextTurnOnAFinishedGameDoesNotChangeTheCurrentTurn(): void
    {
        $game = $this->createGame();
        $game->finishedAt = new \DateTimeImmutable();
        $this->entityManager->flush();

        $this->createLiveComponent('OperatorConsole', ['game' => $game])->call('nextTurn');

        $this->assertSame(1, $this->reloadGame($game)->currentTurn);
    }

    #[Test]
    public function aPlayerTabPanelContainsItsOrderCardsRegionWithAnEmptyCardForTheCurrentTurn(): void
    {
        $game = $this->createGame();
        $player = $this->createPlayer($game, 'Alice');

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
        $game = $this->createGame();
        $player = $this->createPlayer($game, 'Alice');

        $console = $this->createLiveComponent('OperatorConsole', ['game' => $game])->component();
        $stampAtTurnOne = $console->ordersStampFor($player);

        $game->currentTurn = 2;
        $this->entityManager->flush();

        $stampAtTurnTwo = $console->ordersStampFor($this->reloadPlayer($player));

        $this->assertNotSame($stampAtTurnOne, $stampAtTurnTwo);
    }

    #[Test]
    public function advancingTurnsRendersOneOrderCardPerElapsedTurnInThePlayerTab(): void
    {
        $game = $this->createGame();
        $player = $this->createPlayer($game, 'Alice');

        $console = $this->createLiveComponent('OperatorConsole', ['game' => $game]);
        $console->call('nextTurn');
        $rendered = $console->call('nextTurn')->render();

        $this->assertSame(3, $this->reloadGame($game)->currentTurn);

        $playerDetails = $rendered->crawler()->filter("details[data-tab-id=\"{$player->id}\"]");

        $cardTexts = $playerDetails->filter('article')->each(
            static fn ($node): string => trim($node->text()),
        );

        $this->assertCount(3, $cardTexts);
        $this->assertTrue((bool) preg_grep('/Turn 1\b/', $cardTexts));
        $this->assertTrue((bool) preg_grep('/Turn 2\b/', $cardTexts));
        $this->assertTrue((bool) preg_grep('/Turn 3\b/', $cardTexts));
    }

    private function createGame(): GameSession
    {
        $game = new GameSession();
        $this->entityManager->persist($game);
        $this->entityManager->flush();

        return $game;
    }

    private function createPlayer(GameSession $game, string $name): Player
    {
        $player = new Player($game, $name);
        $player->empire = 'minoa';
        $this->entityManager->persist($player);
        $this->entityManager->flush();

        return $player;
    }

    private function freshEntityManager(): EntityManagerInterface
    {
        return self::getContainer()->get(EntityManagerInterface::class);
    }

    private function reloadGame(GameSession $game): GameSession
    {
        $reloaded = $this->freshEntityManager()->find(GameSession::class, $game->id);
        $this->assertInstanceOf(GameSession::class, $reloaded);

        return $reloaded;
    }

    private function reloadPlayer(Player $player): Player
    {
        $reloaded = $this->freshEntityManager()->find(Player::class, $player->id);
        $this->assertInstanceOf(Player::class, $reloaded);

        return $reloaded;
    }
}
