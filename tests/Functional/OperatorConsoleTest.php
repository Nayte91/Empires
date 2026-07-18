<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\GameSession;
use App\Entity\Order;
use App\Entity\Player;
use App\Shop\OrderStatus;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\UX\LiveComponent\Test\InteractsWithLiveComponents;

final class OperatorConsoleTest extends WebTestCase
{
    use InteractsWithLiveComponents;

    private EntityManagerInterface $entityManager;

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

        self::assertStringNotContainsString('data-mercure-refresh-events-value', $rendered);
    }

    #[Test]
    public function rendersTheCurrentTurn(): void
    {
        $game = $this->createGame();

        $rendered = $this->createLiveComponent('OperatorConsole', ['game' => $game])->render();

        self::assertStringContainsString('Turn 1', $rendered->toString());
    }

    #[Test]
    public function previousTurnButtonIsDisabledOnTurnOne(): void
    {
        $game = $this->createGame();

        $rendered = $this->createLiveComponent('OperatorConsole', ['game' => $game])->render();

        $button = $rendered->crawler()->filter('button')->reduce(
            static fn ($node): bool => str_contains((string) $node->text(), 'Previous turn'),
        );

        self::assertNotNull($button->attr('disabled'));
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

        self::assertNull($button->attr('disabled'));
    }

    #[Test]
    public function nextTurnIncrementsTheCurrentTurn(): void
    {
        $game = $this->createGame();

        $this->createLiveComponent('OperatorConsole', ['game' => $game])->call('nextTurn');

        self::assertSame(2, $this->reloadGame($game)->currentTurn);
    }

    #[Test]
    public function nextTurnClampsAtTwenty(): void
    {
        $game = $this->createGame();
        $game->currentTurn = 20;
        $this->entityManager->flush();

        $this->createLiveComponent('OperatorConsole', ['game' => $game])->call('nextTurn');

        self::assertSame(20, $this->reloadGame($game)->currentTurn);
    }

    #[Test]
    public function finishGameFillsInFinishedAt(): void
    {
        $game = $this->createGame();

        $this->createLiveComponent('OperatorConsole', ['game' => $game])->call('finishGame');

        self::assertInstanceOf(\DateTimeImmutable::class, $this->reloadGame($game)->finishedAt);
    }

    #[Test]
    public function nextTurnOnAFinishedGameDoesNotChangeTheCurrentTurn(): void
    {
        $game = $this->createGame();
        $game->finishedAt = new \DateTimeImmutable();
        $this->entityManager->flush();

        $this->createLiveComponent('OperatorConsole', ['game' => $game])->call('nextTurn');

        self::assertSame(1, $this->reloadGame($game)->currentTurn);
    }

    #[Test]
    public function adjustCitiesPersistsTheDelta(): void
    {
        $game = $this->createGame();
        $player = $this->createPlayer($game, 'Bob');

        $this->createLiveComponent('OperatorConsole', ['game' => $game])
            ->call('adjustCities', ['playerId' => (string) $player->id, 'delta' => 1])
        ;

        self::assertSame(1, $this->reloadPlayer($player)->cities);
    }

    #[Test]
    public function adjustCitiesClampsAtNine(): void
    {
        $game = $this->createGame();
        $player = $this->createPlayer($game, 'Bob');
        $player->cities = 9;
        $this->entityManager->flush();

        $this->createLiveComponent('OperatorConsole', ['game' => $game])
            ->call('adjustCities', ['playerId' => (string) $player->id, 'delta' => 1])
        ;

        self::assertSame(9, $this->reloadPlayer($player)->cities);
    }

    #[Test]
    public function adjustCitiesClampsAtZero(): void
    {
        $game = $this->createGame();
        $player = $this->createPlayer($game, 'Bob');

        $this->createLiveComponent('OperatorConsole', ['game' => $game])
            ->call('adjustCities', ['playerId' => (string) $player->id, 'delta' => -1])
        ;

        self::assertSame(0, $this->reloadPlayer($player)->cities);
    }

    #[Test]
    public function adjustAstPositionPersistsTheDelta(): void
    {
        $game = $this->createGame();
        $player = $this->createPlayer($game, 'Bob');

        $this->createLiveComponent('OperatorConsole', ['game' => $game])
            ->call('adjustAstPosition', ['playerId' => (string) $player->id, 'delta' => 1])
        ;

        self::assertSame(1, $this->reloadPlayer($player)->astPosition);
    }

    #[Test]
    public function adjustAstPositionClampsAtFifteen(): void
    {
        $game = $this->createGame();
        $player = $this->createPlayer($game, 'Bob');
        $player->astPosition = 15;
        $this->entityManager->flush();

        $this->createLiveComponent('OperatorConsole', ['game' => $game])
            ->call('adjustAstPosition', ['playerId' => (string) $player->id, 'delta' => 1])
        ;

        self::assertSame(15, $this->reloadPlayer($player)->astPosition);
    }

    #[Test]
    public function adjustAstPositionClampsAtZero(): void
    {
        $game = $this->createGame();
        $player = $this->createPlayer($game, 'Bob');

        $this->createLiveComponent('OperatorConsole', ['game' => $game])
            ->call('adjustAstPosition', ['playerId' => (string) $player->id, 'delta' => -1])
        ;

        self::assertSame(0, $this->reloadPlayer($player)->astPosition);
    }

    #[Test]
    public function adjustAstPositionOnAPlayerFromAnotherGameIsNoOp(): void
    {
        $game = $this->createGame();
        $otherGame = $this->createGame();
        $otherPlayer = $this->createPlayer($otherGame, 'Eve');

        $this->createLiveComponent('OperatorConsole', ['game' => $game])
            ->call('adjustAstPosition', ['playerId' => (string) $otherPlayer->id, 'delta' => 1])
        ;

        self::assertSame(0, $this->reloadPlayer($otherPlayer)->astPosition);
    }

    #[Test]
    public function adjustCensusPersistsTheDelta(): void
    {
        $game = $this->createGame();
        $player = $this->createPlayer($game, 'Bob');

        $this->createLiveComponent('OperatorConsole', ['game' => $game])
            ->call('adjustCensus', ['playerId' => (string) $player->id, 'delta' => 1])
        ;

        self::assertSame(2, $this->reloadPlayer($player)->census);
    }

    #[Test]
    public function adjustCensusClampsAtFiftyFive(): void
    {
        $game = $this->createGame();
        $player = $this->createPlayer($game, 'Bob');
        $player->census = 55;
        $this->entityManager->flush();

        $this->createLiveComponent('OperatorConsole', ['game' => $game])
            ->call('adjustCensus', ['playerId' => (string) $player->id, 'delta' => 1])
        ;

        self::assertSame(55, $this->reloadPlayer($player)->census);
    }

    #[Test]
    public function adjustTreasuryPersistsTheDelta(): void
    {
        $game = $this->createGame();
        $player = $this->createPlayer($game, 'Bob');

        $this->createLiveComponent('OperatorConsole', ['game' => $game])
            ->call('adjustTreasury', ['playerId' => (string) $player->id, 'delta' => 1])
        ;

        self::assertSame(1, $this->reloadPlayer($player)->treasury);
    }

    #[Test]
    public function adjustTreasuryClampsAtFiftyFive(): void
    {
        $game = $this->createGame();
        $player = $this->createPlayer($game, 'Bob');
        $player->treasury = 55;
        $this->entityManager->flush();

        $this->createLiveComponent('OperatorConsole', ['game' => $game])
            ->call('adjustTreasury', ['playerId' => (string) $player->id, 'delta' => 1])
        ;

        self::assertSame(55, $this->reloadPlayer($player)->treasury);
    }

    #[Test]
    public function adjustTreasuryClampsAtZero(): void
    {
        $game = $this->createGame();
        $player = $this->createPlayer($game, 'Bob');

        $this->createLiveComponent('OperatorConsole', ['game' => $game])
            ->call('adjustTreasury', ['playerId' => (string) $player->id, 'delta' => -1])
        ;

        self::assertSame(0, $this->reloadPlayer($player)->treasury);
    }

    #[Test]
    public function adjustTreasuryOnAPlayerFromAnotherGameIsNoOp(): void
    {
        $game = $this->createGame();
        $otherGame = $this->createGame();
        $otherPlayer = $this->createPlayer($otherGame, 'Eve');

        $this->createLiveComponent('OperatorConsole', ['game' => $game])
            ->call('adjustTreasury', ['playerId' => (string) $otherPlayer->id, 'delta' => 1])
        ;

        self::assertSame(0, $this->reloadPlayer($otherPlayer)->treasury);
    }

    #[Test]
    public function adjustCitiesOnAPlayerFromAnotherGameIsNoOp(): void
    {
        $game = $this->createGame();
        $otherGame = $this->createGame();
        $otherPlayer = $this->createPlayer($otherGame, 'Eve');

        $this->createLiveComponent('OperatorConsole', ['game' => $game])
            ->call('adjustCities', ['playerId' => (string) $otherPlayer->id, 'delta' => 1])
        ;

        self::assertSame(0, $this->reloadPlayer($otherPlayer)->cities);
    }

    #[Test]
    public function validatingAnOrderWithSufficientMoneyValidatesItAndOwnsTheAdvance(): void
    {
        $order = $this->createPendingOrder('pottery');

        $this->createLiveComponent('OrderTab', ['order' => $order])
            ->set('money', 60)
            ->call('validate')
        ;

        $reloadedOrder = $this->reloadOrder($order);

        self::assertSame(OrderStatus::Validated, $reloadedOrder->status);
        self::assertContains('pottery', $reloadedOrder->player->advances);
    }

    #[Test]
    public function validatingAnOrderWithInsufficientMoneyDisplaysAnErrorAndKeepsItPending(): void
    {
        $order = $this->createPendingOrder('pottery');

        $rendered = $this->createLiveComponent('OrderTab', ['order' => $order])
            ->set('money', 10)
            ->call('validate')
            ->render()
        ;

        self::assertSame(OrderStatus::Pending, $this->reloadOrder($order)->status);
        self::assertStringContainsString('Insufficient money', $rendered->toString());
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
        $this->entityManager->persist($player);
        $this->entityManager->flush();

        return $player;
    }

    private function createPendingOrder(string ...$slugs): Order
    {
        $game = $this->createGame();
        $player = new Player($game, 'Bob');
        $this->entityManager->persist($player);

        $order = new Order($player, $game->currentTurn);
        $order->replaceLines($slugs);
        $this->entityManager->persist($order);
        $this->entityManager->flush();

        return $order;
    }

    private function freshEntityManager(): EntityManagerInterface
    {
        return self::getContainer()->get(EntityManagerInterface::class);
    }

    private function reloadGame(GameSession $game): GameSession
    {
        $reloaded = $this->freshEntityManager()->find(GameSession::class, $game->id);
        self::assertInstanceOf(GameSession::class, $reloaded);

        return $reloaded;
    }

    private function reloadOrder(Order $order): Order
    {
        $reloaded = $this->freshEntityManager()->find(Order::class, $order->id);
        self::assertInstanceOf(Order::class, $reloaded);

        return $reloaded;
    }

    private function reloadPlayer(Player $player): Player
    {
        $reloaded = $this->freshEntityManager()->find(Player::class, $player->id);
        self::assertInstanceOf(Player::class, $reloaded);

        return $reloaded;
    }
}
