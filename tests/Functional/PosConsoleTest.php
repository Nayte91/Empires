<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Game;
use App\Entity\Order;
use App\Entity\Player;
use App\Enumeration\OrderStatus;
use App\Repository\OrderRepository;
use App\Shop\Service\OrderValidator;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\UX\LiveComponent\Test\InteractsWithLiveComponents;

/**
 * Acceptance tests for the operator console's cashier (POS) flow: an operator
 * picks a player, builds a ticket of advances and checks it out directly
 * (App\Shop\Service\DirectSale), without the player ever using their own
 * kiosk (App\Component\Shop).
 */
final class PosConsoleTest extends WebTestCase
{
    use InteractsWithLiveComponents;

    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        self::bootKernel();

        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
    }

    #[Test]
    public function directSaleValidatesTheOrderAndCreditsTheDiscountedAdvancesThenClosesThePosPanel(): void
    {
        [$game, $alice] = $this->createGameWithAliceAndBob();

        $console = $this->createLiveComponent('OperatorConsole', ['game' => $game]);
        $console->call('startOrder');
        $console->set('selectedPlayerId', (string) $alice->id);
        $console->call('addToTicket', ['key' => 'democracy']);
        $console->call('addToTicket', ['key' => 'pottery']);
        $console->set('money', 250);
        $console->call('checkout');

        $order = $this->freshOrderRepository()->findOneByPlayerAndTurn($alice, $game->currentTurn);
        self::assertInstanceOf(Order::class, $order);
        self::assertSame(OrderStatus::Validated, $order->status);
        self::assertSame(
            [
                ['key' => 'democracy', 'netCost' => 200],
                ['key' => 'pottery', 'netCost' => 50],
            ],
            $order->lines,
        );
        self::assertSame(['agriculture', 'democracy', 'pottery'], $this->reloadPlayer($alice)->advances);

        $consoleComponent = $console->component();
        self::assertFalse($consoleComponent->creatingOrder);
        self::assertSame('', $consoleComponent->selectedPlayerId);
        self::assertSame([], $consoleComponent->ticket);
    }

    #[Test]
    public function selectingAPlayerWithAPendingOrderPreloadsTheTicketAndCheckoutConcludesTheSameOrder(): void
    {
        [$game, , $bob] = $this->createGameWithAliceAndBob();
        $pendingOrder = $this->createPendingOrderFor($bob, ['pottery']);

        $console = $this->createLiveComponent('OperatorConsole', ['game' => $game]);
        $console->call('startOrder');
        $console->set('selectedPlayerId', (string) $bob->id);

        self::assertSame(['pottery'], $console->component()->ticket);

        // Bob owns nothing, so pottery is at full price (60), unlike Alice's
        // agriculture-discounted 50 used elsewhere in this file.
        $console->set('money', 60);
        $console->call('checkout');

        $order = $this->freshOrderRepository()->findOneByPlayerAndTurn($bob, $game->currentTurn);
        self::assertInstanceOf(Order::class, $order);
        self::assertSame($pendingOrder->id->toRfc4122(), $order->id->toRfc4122());
        self::assertSame(OrderStatus::Validated, $order->status);
    }

    #[Test]
    public function discountsAppearOnlyAfterPlayerSelectionWithTheSelectedPlayersColors(): void
    {
        [$game, $alice] = $this->createGameWithAliceAndBob();

        $console = $this->createLiveComponent('OperatorConsole', ['game' => $game]);
        $console->call('startOrder');

        self::assertStringNotContainsString('discounts__category', $console->render()->toString());

        $console->set('selectedPlayerId', (string) $alice->id);
        $rendered = $console->render()->toString();

        self::assertStringContainsString('discounts__category', $rendered);
        self::assertStringContainsString('--category-color: #F7941E', $rendered);
        self::assertStringContainsString('--category-color: #39B54A', $rendered);
    }

    #[Test]
    public function aPlayerValidatedThisTurnIsDisabledInTheSelectAndCannotBeSoldTo(): void
    {
        [$game, $alice] = $this->createGameWithAliceAndBob();
        $this->validateOrderFor($alice, ['democracy'], 200);

        $rendered = $this->createLiveComponent('OperatorConsole', ['game' => $game])
            ->call('startOrder')
            ->render()
            ->toString()
        ;
        self::assertMatchesRegularExpression(
            '/<option[^>]*value="'.preg_quote((string) $alice->id, '/').'"[^>]*disabled[^>]*>/',
            $rendered,
        );
        self::assertStringContainsString('(order validated this turn)', $rendered);

        $console = $this->createLiveComponent('OperatorConsole', ['game' => $game]);
        $console->call('startOrder');
        $console->set('selectedPlayerId', (string) $alice->id);
        $console->call('addToTicket', ['key' => 'pottery']);
        $console->set('money', 50);
        $rendered = $console->call('checkout')->render()->toString();

        self::assertStringContainsString('already been validated', $rendered);
    }

    #[Test]
    public function insufficientMoneyDisplaysAnErrorAndPersistsNothing(): void
    {
        [$game, , $bob] = $this->createGameWithAliceAndBob();

        $console = $this->createLiveComponent('OperatorConsole', ['game' => $game]);
        $console->call('startOrder');
        $console->set('selectedPlayerId', (string) $bob->id);
        $console->call('addToTicket', ['key' => 'pottery']);
        $console->set('money', 10);
        $rendered = $console->call('checkout')->render()->toString();

        self::assertStringContainsString('Insufficient money', $rendered);
        self::assertNull($this->freshOrderRepository()->findOneByPlayerAndTurn($bob, $game->currentTurn));
    }

    #[Test]
    public function theCreateOrderPanelShowsAllFiftyOneAdvancesForASelectedPlayer(): void
    {
        [$game, , $bob] = $this->createGameWithAliceAndBob();

        $console = $this->createLiveComponent('OperatorConsole', ['game' => $game]);
        $console->call('startOrder');
        $rendered = $console->set('selectedPlayerId', (string) $bob->id)->render()->toString();

        self::assertSame(51, substr_count($rendered, 'product-card__add'));
    }

    #[Test]
    public function addingAnAlreadyOwnedAdvanceToTheTicketIsRefused(): void
    {
        [$game, $alice] = $this->createGameWithAliceAndBob();

        $console = $this->createLiveComponent('OperatorConsole', ['game' => $game]);
        $console->call('startOrder');
        $console->set('selectedPlayerId', (string) $alice->id);
        $rendered = $console->call('addToTicket', ['key' => 'agriculture'])->render()->toString();

        self::assertSame([], $console->component()->ticket);
        self::assertStringContainsString('already owned', $rendered);
    }

    /** @return array{Game, Player, Player} */
    private function createGameWithAliceAndBob(): array
    {
        $game = new Game();
        $alice = new Player($game, 'Alice');
        $alice->ownAdvances(['agriculture']);
        $bob = new Player($game, 'Bob');

        $this->entityManager->persist($game);
        $this->entityManager->persist($alice);
        $this->entityManager->persist($bob);
        $this->entityManager->flush();

        return [$game, $alice, $bob];
    }

    /** @param list<string> $slugs */
    private function createPendingOrderFor(Player $player, array $slugs): Order
    {
        $order = new Order($player, $player->game->currentTurn);
        $order->replaceLines($slugs);
        $this->entityManager->persist($order);
        $this->entityManager->flush();

        return $order;
    }

    /** @param list<string> $slugs */
    private function validateOrderFor(Player $player, array $slugs, int $money): Order
    {
        $order = $this->createPendingOrderFor($player, $slugs);

        self::getContainer()->get(OrderValidator::class)->validate($order, $money);

        return $order;
    }

    private function freshOrderRepository(): OrderRepository
    {
        return self::getContainer()->get(OrderRepository::class);
    }

    private function reloadPlayer(Player $player): Player
    {
        $reloaded = self::getContainer()->get(EntityManagerInterface::class)->find(Player::class, $player->id);
        self::assertInstanceOf(Player::class, $reloaded);

        return $reloaded;
    }
}
