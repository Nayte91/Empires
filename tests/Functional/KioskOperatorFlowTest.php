<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\GameSession;
use App\Entity\Order;
use App\Entity\Player;
use App\Repository\OrderRepository;
use App\Shop\OrderStatus;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\UX\LiveComponent\Test\InteractsWithLiveComponents;

/**
 * End-to-end acceptance test for the cashier-kiosk pattern: a player builds a
 * cart in their Shop kiosk, submits it, an operator validates it from the
 * console, and the kiosk reflects the resulting lock/ownership state.
 */
final class KioskOperatorFlowTest extends WebTestCase
{
    use InteractsWithLiveComponents;

    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        self::bootKernel();

        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
    }

    #[Test]
    public function aliceKioskAppliesAgricultureCreditsToCartPrices(): void
    {
        [, $alice] = $this->createGameWithAliceAndBob();

        $shop = $this->createLiveComponent('Shop', ['player' => $alice]);
        $shop->call('addToCart', ['key' => 'democracy']);
        $shop->call('addToCart', ['key' => 'pottery']);

        $rendered = $shop->render()->toString();

        self::assertMatchesRegularExpression('/id="product-democracy".*?data-price-net>200</s', $rendered);
        self::assertMatchesRegularExpression('/id="product-pottery".*?data-price-net>50</s', $rendered);
        self::assertStringContainsString('Total: 250', $rendered);
    }

    #[Test]
    public function submittingTheCartCreatesAPendingOrderInDatabase(): void
    {
        [$game, $alice] = $this->createGameWithAliceAndBob();

        $this->submitAliceDemocracyAndPotteryOrder($alice);

        $order = $this->freshOrderRepository()->findOneByPlayerAndTurn($alice, $game->currentTurn);

        self::assertInstanceOf(Order::class, $order);
        self::assertSame(OrderStatus::Pending, $order->status);
        self::assertSame(['democracy', 'pottery'], $order->lines);
    }

    #[Test]
    public function thePendingOrderAppearsAsAPendingCardInThePlayersOrderHistory(): void
    {
        [$game, $alice] = $this->createGameWithAliceAndBob();

        $this->submitAliceDemocracyAndPotteryOrder($alice);

        $rendered = $this->createLiveComponent('PlayerOrders', [
            'player' => $alice,
            'ordersStamp' => '',
        ])->render()->toString();

        self::assertStringContainsString('Turn '.$game->currentTurn, $rendered);
        self::assertStringContainsString('Democracy', $rendered);
        self::assertStringContainsString('Pottery', $rendered);
        self::assertStringContainsString('pending', $rendered);
    }

    #[Test]
    public function openingThePosOnAPendingCardPreloadsItsTicket(): void
    {
        [$game, $alice] = $this->createGameWithAliceAndBob();

        $this->submitAliceDemocracyAndPotteryOrder($alice);

        $component = $this->createLiveComponent('PlayerOrders', [
            'player' => $alice,
            'ordersStamp' => '',
        ]);
        $component->call('openPos', ['turn' => $game->currentTurn]);

        self::assertSame(['democracy', 'pottery'], $component->component()->ticket);
    }

    #[Test]
    public function validatingFreezesLinesGrantsAdvancesAndValidatesTheOrderAndTheCardShowsItsTotalAndVictoryPoints(): void
    {
        [$game, $alice] = $this->createGameWithAliceAndBob();

        $this->submitAliceDemocracyAndPotteryOrder($alice);
        $order = $this->freshOrderRepository()->findOneByPlayerAndTurn($alice, $game->currentTurn);
        self::assertInstanceOf(Order::class, $order);

        $this->validateOrder($order);

        $reloadedOrder = $this->reloadOrder($order);

        self::assertSame(OrderStatus::Validated, $reloadedOrder->status);
        self::assertSame(
            [
                ['key' => 'democracy', 'netCost' => 200],
                ['key' => 'pottery', 'netCost' => 50],
            ],
            $reloadedOrder->lines,
        );
        self::assertSame(250, $reloadedOrder->total);
        self::assertSame(['agriculture', 'democracy', 'pottery'], $this->reloadPlayer($alice)->advances);

        $rendered = $this->createLiveComponent('PlayerOrders', [
            'player' => $alice,
            'ordersStamp' => '',
        ])->render()->toString();

        // democracy: 6 points, pottery: 1 point (config/game/advances.yaml).
        self::assertStringContainsString('Total: 250', $rendered);
        self::assertStringContainsString('VP: 7', $rendered);
        self::assertStringContainsString('validated', $rendered);
    }

    #[Test]
    public function aliceKioskLocksForTheTurnAfterValidationWhileBobKioskStaysOpen(): void
    {
        [$game, $alice, $bob] = $this->createGameWithAliceAndBob();

        $this->submitAndValidateAliceOrder($alice, $game);

        $aliceShop = $this->createLiveComponent('Shop', ['player' => $alice]);
        self::assertTrue($this->getShopComponent($aliceShop)->isLockedForTurn());

        $aliceRendered = $aliceShop->render()->toString();
        self::assertStringContainsString('Order validated for this turn.', $aliceRendered);
        self::assertStringNotContainsString('id="product-democracy"', $aliceRendered);
        self::assertStringContainsString('Democracy', $aliceRendered);

        $bobShop = $this->createLiveComponent('Shop', ['player' => $bob]);
        self::assertFalse($this->getShopComponent($bobShop)->isLockedForTurn());

        $bobRendered = $bobShop->render()->toString();
        self::assertMatchesRegularExpression('/id="product-democracy".*?data-price-net>220</s', $bobRendered);
    }

    #[Test]
    public function nextTurnUnlocksAliceKioskAndRepricesHerCatalogWithNewCredits(): void
    {
        [$game, $alice] = $this->createGameWithAliceAndBob();

        $this->submitAndValidateAliceOrder($alice, $game);

        $this->createLiveComponent('OperatorConsole', ['game' => $game])->call('nextTurn');
        self::assertSame(2, $this->reloadGame($game)->currentTurn);

        $aliceShop = $this->createLiveComponent('Shop', ['player' => $alice]);
        self::assertFalse($this->getShopComponent($aliceShop)->isLockedForTurn());

        // 'law' costs 150 and grants no direct discount to Alice, but its sole
        // category (civic) receives a 20-point credit from the democracy she
        // now owns: 150 - 20 = 130.
        $rendered = $aliceShop->render()->toString();
        self::assertMatchesRegularExpression('/id="product-law".*?data-price-net>130</s', $rendered);
    }

    #[Test]
    public function resubmittingAnOrderReplacesItsLinesOnTheSameOrderRow(): void
    {
        [$game, , $bob] = $this->createGameWithAliceAndBob();

        $firstShop = $this->createLiveComponent('Shop', ['player' => $bob]);
        $firstShop->call('addToCart', ['key' => 'pottery']);
        $firstShop->call('submitOrder');

        $firstOrder = $this->freshOrderRepository()->findOneByPlayerAndTurn($bob, $game->currentTurn);
        self::assertInstanceOf(Order::class, $firstOrder);
        self::assertSame(['pottery'], $firstOrder->lines);

        $secondShop = $this->createLiveComponent('Shop', ['player' => $bob]);
        $secondShop->call('addToCart', ['key' => 'democracy']);
        $secondShop->call('submitOrder');

        $secondOrder = $this->freshOrderRepository()->findOneByPlayerAndTurn($bob, $game->currentTurn);
        self::assertInstanceOf(Order::class, $secondOrder);
        self::assertSame($firstOrder->id->toRfc4122(), $secondOrder->id->toRfc4122());
        self::assertSame(['democracy'], $secondOrder->lines);

        $ordersForTurn = $this->freshEntityManager()->getRepository(Order::class)->createQueryBuilder('o')
            ->andWhere('o.player = :player')
            ->andWhere('o.turn = :turn')
            ->setParameter('player', $bob->id, 'uuid')
            ->setParameter('turn', $game->currentTurn)
            ->getQuery()
            ->getResult()
        ;
        self::assertCount(1, $ordersForTurn);
    }

    #[Test]
    public function cartAdditionsInAliceKioskNeverAppearInBobKiosk(): void
    {
        [, $alice, $bob] = $this->createGameWithAliceAndBob();

        $aliceShop = $this->createLiveComponent('Shop', ['player' => $alice]);
        $aliceShop->call('addToCart', ['key' => 'pottery']);

        // createLiveComponent() defaults both kiosks to the same 'test.client'
        // service, i.e. the same session. Isolation here is proven by
        // CartRepository keying cart storage per player UUID (see
        // App\Shop\CartRepository::sessionKey()), not by separate sessions.
        $bobShop = $this->createLiveComponent('Shop', ['player' => $bob]);
        $bobRendered = $bobShop->render()->toString();

        self::assertStringNotContainsString('data-in-cart', $bobRendered);
        self::assertStringContainsString('Your cart is empty.', $bobRendered);
    }

    /** @return array{GameSession, Player, Player} */
    private function createGameWithAliceAndBob(): array
    {
        $game = new GameSession();
        $alice = new Player($game, 'Alice');
        $alice->ownAdvances(['agriculture']);
        $bob = new Player($game, 'Bob');

        $this->entityManager->persist($game);
        $this->entityManager->persist($alice);
        $this->entityManager->persist($bob);
        $this->entityManager->flush();

        return [$game, $alice, $bob];
    }

    private function submitAliceDemocracyAndPotteryOrder(Player $alice): void
    {
        $shop = $this->createLiveComponent('Shop', ['player' => $alice]);
        $shop->call('addToCart', ['key' => 'democracy']);
        $shop->call('addToCart', ['key' => 'pottery']);
        $shop->call('submitOrder');
    }

    private function validateOrder(Order $order): void
    {
        $component = $this->createLiveComponent('PlayerOrders', [
            'player' => $order->player,
            'ordersStamp' => '',
        ]);
        $component->call('openPos', ['turn' => $order->turn]);
        $component->call('checkout');
    }

    private function submitAndValidateAliceOrder(Player $alice, GameSession $game): void
    {
        $this->submitAliceDemocracyAndPotteryOrder($alice);
        $order = $this->freshOrderRepository()->findOneByPlayerAndTurn($alice, $game->currentTurn);
        self::assertInstanceOf(Order::class, $order);

        $this->validateOrder($order);
    }

    private function freshEntityManager(): EntityManagerInterface
    {
        return self::getContainer()->get(EntityManagerInterface::class);
    }

    private function freshOrderRepository(): OrderRepository
    {
        return self::getContainer()->get(OrderRepository::class);
    }

    private function reloadPlayer(Player $player): Player
    {
        $reloaded = $this->freshEntityManager()->find(Player::class, $player->id);
        self::assertInstanceOf(Player::class, $reloaded);

        return $reloaded;
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

    private function getShopComponent(object $component): object
    {
        return $component->component();
    }
}
