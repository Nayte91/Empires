<?php

declare(strict_types=1);

namespace App\Tests\Shop;

use App\Entity\GameSession;
use App\Entity\Order;
use App\Entity\Player;
use App\Game\Shop\AdvanceFulfillment;
use App\Game\Shop\PlayerBuyerProvider;
use App\Game\Shop\ShopConnector;
use App\Repository\OrderRepository;
use App\Repository\PlayerRepository;
use App\Shop\Command\SellDirect;
use App\Shop\Command\SubmitOrder;
use App\Shop\CommandHandler\SellDirectHandler;
use App\Shop\CommandHandler\SubmitOrderHandler;
use App\Shop\Doctrine\DoctrineTransaction;
use App\Shop\Dto\LineIntent;
use App\Shop\Dto\OrderLine;
use App\Shop\Event\ShopEventPublisher;
use App\Shop\Exception\OrderException;
use App\Shop\OrderStatus;
use App\Shop\ProductProviderInterface;
use App\Shop\Promotion\AppliedPromotion;
use App\Shop\Promotion\PromotionEngine;
use App\Shop\Promotion\PromotionType;
use App\Shop\Service\LineQuoter;
use App\Shop\Service\OrderValidator;
use App\Shop\Service\PriceCalculator;
use App\Tests\Support\Mercure\RecordingHub;
use App\Tests\Support\Workflow\ShopOrderStateMachine;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class DirectSaleTest extends WebTestCase
{
    private EntityManagerInterface $entityManager;
    private OrderRepository $orderRepository;
    private SubmitOrderHandler $submitOrderHandler;
    private SellDirectHandler $sellDirectHandler;
    private RecordingHub $hub;

    protected function setUp(): void
    {
        self::bootKernel();

        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->orderRepository = self::getContainer()->get(OrderRepository::class);
        $playerRepository = self::getContainer()->get(PlayerRepository::class);
        $productProvider = self::getContainer()->get(ProductProviderInterface::class);
        $this->hub = self::getContainer()->get(RecordingHub::class);

        // SubmitOrderHandler, OrderValidator and SellDirectHandler are built by hand
        // rather than fetched from the container, so they share the guard-free
        // ShopOrderStateMachine test double instead of the container's registered
        // shop_order workflow (see OrderFlowTest). This file never exercises reject —
        // the guard that motivates the test double — so this is inherited convention,
        // not a hard requirement here. Built from the shared EntityManager/
        // OrderRepository/PlayerRepository/ProductProviderInterface instances, following
        // OrderFlowTest's convention.
        $shopConnector = new ShopConnector($this->orderRepository);
        $lineQuoter = new LineQuoter($productProvider, new PriceCalculator(), new PromotionEngine(), $shopConnector);
        $shopOrderStateMachine = ShopOrderStateMachine::create();
        $eventBus = self::getContainer()->get(ShopEventPublisher::class);
        $fulfillment = new AdvanceFulfillment($playerRepository);
        $buyerProvider = new PlayerBuyerProvider($playerRepository, $shopConnector);
        // Single shared DoctrineTransaction instance, matching the singleton the
        // container injects in production. SellDirectHandler doesn't open a scope of
        // its own — its mutations stay unflushed and ride into OrderValidator's
        // transactional() call via the unit of work, same as today. OrderValidator
        // is the only caller that opens a scope in this flow, but sharing the
        // instance still matters: two separate instances would silently open a real
        // nested transaction (SAVEPOINT) instead of joining the moment anything ever
        // does nest.
        $transaction = new DoctrineTransaction($this->entityManager);
        $this->submitOrderHandler = new SubmitOrderHandler(
            $transaction,
            $this->orderRepository,
            $lineQuoter,
            $shopOrderStateMachine,
            $eventBus,
            $buyerProvider,
        );
        $orderValidator = new OrderValidator(
            $transaction,
            $lineQuoter,
            $shopOrderStateMachine,
            $buyerProvider,
            $eventBus,
            $fulfillment,
        );
        $this->sellDirectHandler = new SellDirectHandler(
            $this->orderRepository,
            $orderValidator,
            $lineQuoter,
            $shopOrderStateMachine,
            $eventBus,
            $buyerProvider,
        );
    }

    #[Test]
    public function sellValidatesOrderImmediatelyAndOwnsAdvances(): void
    {
        $player = $this->createPlayer();
        $player->ownAdvances(['agriculture']);
        $this->entityManager->flush();

        $order = ($this->sellDirectHandler)(new SellDirect($player->id, $this->intents(['democracy', 'pottery']), $player->game->currentTurn));

        $this->assertSame(OrderStatus::Validated, $order->status);
        $this->assertEquals([new OrderLine('democracy', 200), new OrderLine('pottery', 50)], $order->lines);
        $this->assertSame(250, $order->total);
        $this->assertContains('democracy', $player->advances);
        $this->assertContains('pottery', $player->advances);

        $this->entityManager->clear();
        $reloadedOrder = $this->orderRepository->find($order->id);
        $this->assertInstanceOf(Order::class, $reloadedOrder);
        $this->assertSame(OrderStatus::Validated, $reloadedOrder->status);
    }

    #[Test]
    public function sellingLibraryAndDemocracyDiscountsAndFreezesTheDemocracyLine(): void
    {
        $player = $this->createPlayer();

        $order = ($this->sellDirectHandler)(new SellDirect($player->id, $this->intents(['library', 'democracy']), $player->game->currentTurn));

        $this->assertSame(400, $order->total);
        $democracyLine = $order->lines()[1];
        $this->assertSame('democracy', $democracyLine->key);
        $this->assertSame(180, $democracyLine->netCost);
        $this->assertInstanceOf(AppliedPromotion::class, $democracyLine->promotion);
        $this->assertSame(PromotionType::Discount, $democracyLine->promotion->type);
        $this->assertSame('library', $democracyLine->promotion->source);
        $this->assertSame(40, $democracyLine->promotion->amount);
    }

    #[Test]
    public function sellWithExplicitPastTurnCreatesAndValidatesTheOrderOnThatTurn(): void
    {
        $player = $this->createPlayer();
        $player->game->currentTurn = 3;
        $this->entityManager->flush();

        $order = ($this->sellDirectHandler)(new SellDirect($player->id, $this->intents(['pottery']), 1));

        $this->assertSame(1, $order->turn);
        $this->assertSame(OrderStatus::Validated, $order->status);
        $this->assertContains('pottery', $player->advances);
    }

    #[Test]
    public function sellReusesExistingPendingOrderRow(): void
    {
        $player = $this->createPlayer();
        $pendingOrder = ($this->submitOrderHandler)(new SubmitOrder($player->id, $this->intents(['pottery']), $player->game->currentTurn));

        $order = ($this->sellDirectHandler)(new SellDirect($player->id, $this->intents(['democracy']), $player->game->currentTurn));

        $this->assertSame($pendingOrder->id->toRfc4122(), $order->id->toRfc4122());
        $this->assertSame(1, $this->orderRepository->count([
            'player' => $player,
            'turn' => $player->game->currentTurn,
        ]));
        $this->assertEquals([new OrderLine('democracy', 220)], $order->lines);
    }

    #[Test]
    public function sellAfterValidationOfTheTurnThrowsOrderException(): void
    {
        $player = $this->createPlayer();
        ($this->sellDirectHandler)(new SellDirect($player->id, $this->intents(['pottery']), $player->game->currentTurn));

        $this->expectException(OrderException::class);

        ($this->sellDirectHandler)(new SellDirect($player->id, $this->intents(['democracy']), $player->game->currentTurn));
    }

    #[Test]
    public function sellingDirectPublishesOrderUpdatedThenPlayerUpdatedOnTheGameTopic(): void
    {
        $player = $this->createPlayer();

        ($this->sellDirectHandler)(new SellDirect($player->id, $this->intents(['pottery']), $player->game->currentTurn));

        $this->assertSame(['order-updated', 'player-updated'], $this->hub->eventNames());
        $topic = 'empires/game/'.$player->game->id;
        $this->assertSame([$topic, $topic], $this->hub->topics());
    }

    private function createPlayer(): Player
    {
        $game = new GameSession();
        $player = new Player($game, 'Alice');

        $this->entityManager->persist($game);
        $this->entityManager->persist($player);
        $this->entityManager->flush();

        return $player;
    }

    /**
     * @param list<string> $keys
     *
     * @return list<LineIntent>
     */
    private function intents(array $keys): array
    {
        return array_map(static fn (string $key): LineIntent => new LineIntent($key), $keys);
    }
}
