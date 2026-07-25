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
use App\Shop\Command\EraseOrders;
use App\Shop\Command\SellDirect;
use App\Shop\CommandHandler\EraseOrdersHandler;
use App\Shop\CommandHandler\SellDirectHandler;
use App\Shop\Doctrine\DoctrineTransaction;
use App\Shop\Dto\LineIntent;
use App\Shop\Dto\OrderLine;
use App\Shop\Event\ShopEventPublisher;
use App\Shop\OrderStatus;
use App\Shop\ProductProviderInterface;
use App\Shop\Promotion\PromotionEngine;
use App\Shop\Service\LineQuoter;
use App\Shop\Service\OrderValidator;
use App\Shop\Service\PriceCalculator;
use App\Tests\Support\Mercure\RecordingHub;
use App\Tests\Support\Workflow\ShopOrderStateMachine;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class OrderEraserTest extends WebTestCase
{
    private EntityManagerInterface $entityManager;
    private OrderRepository $orderRepository;
    private SellDirectHandler $sellDirectHandler;
    private EraseOrdersHandler $eraseOrdersHandler;
    private ShopConnector $shopConnector;
    private RecordingHub $hub;

    protected function setUp(): void
    {
        self::bootKernel();

        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->orderRepository = self::getContainer()->get(OrderRepository::class);
        $playerRepository = self::getContainer()->get(PlayerRepository::class);
        $this->hub = self::getContainer()->get(RecordingHub::class);

        // SellDirectHandler, OrderValidator and EraseOrdersHandler are built by hand
        // rather than fetched from the container, so they share the guard-free
        // ShopOrderStateMachine test double instead of the container's registered
        // shop_order workflow (see OrderFlowTest/RejectOrderTest). This file never
        // exercises reject — the guard that motivates the test double — so this is
        // inherited convention, not a hard requirement here. Built from the shared
        // EntityManager/OrderRepository/PlayerRepository, following DirectSaleTest's
        // convention.
        // RecordingHub (registered for the real HubInterface under config/services.yaml
        // when@test) keeps every publish() call in-process — no network I/O happens
        // during the suite, and the sequence stays assertable.
        $productProvider = self::getContainer()->get(ProductProviderInterface::class);
        $this->shopConnector = new ShopConnector($this->orderRepository);
        $lineQuoter = new LineQuoter($productProvider, new PriceCalculator(), new PromotionEngine(), $this->shopConnector);
        $shopOrderStateMachine = ShopOrderStateMachine::create();
        $eventBus = self::getContainer()->get(ShopEventPublisher::class);
        $fulfillment = new AdvanceFulfillment($playerRepository);
        $buyerProvider = new PlayerBuyerProvider($playerRepository, $this->shopConnector);
        // Single shared DoctrineTransaction instance, matching the singleton the
        // container injects in production. SellDirectHandler doesn't open a scope of
        // its own — its mutations stay unflushed and ride into OrderValidator's
        // transactional() call via the unit of work, same as today. EraseOrdersHandler
        // opens its own independent scope. Sharing the instance still matters: two
        // separate instances would silently open a real nested transaction
        // (SAVEPOINT) instead of joining the moment anything ever does nest.
        $transaction = new DoctrineTransaction($this->entityManager);
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
        $this->eraseOrdersHandler = new EraseOrdersHandler($transaction, $this->orderRepository, $buyerProvider, $eventBus, $fulfillment);
    }

    #[Test]
    public function erasingAPendingOrderDeletesItAndTouchesNothingElse(): void
    {
        $player = $this->createPlayer();
        $order = $this->createPendingOrder($player, 1, ['pottery']);

        ($this->eraseOrdersHandler)(new EraseOrders($player->id, [1]));

        $this->assertNotInstanceOf(Order::class, $this->orderRepository->find($order->id));
        $this->assertSame([], $this->reloadPlayer($player)->advances);
    }

    #[Test]
    public function erasingExplicitWindowsDisownsValidatedAdvancesButNotPendingOnesAndRemovesAll(): void
    {
        $player = $this->createPlayer();
        $player->ownAdvances(['agriculture']);
        $this->entityManager->flush();

        $turnOneOrder = ($this->sellDirectHandler)(new SellDirect($player->id, $this->intents(['pottery']), 1));
        $turnTwoOrder = ($this->sellDirectHandler)(new SellDirect($player->id, $this->intents(['democracy']), 2));
        $turnThreeOrder = $this->createPendingOrder($player, 3, ['law']);

        ($this->eraseOrdersHandler)(new EraseOrders($player->id, [1, 2, 3]));

        $this->assertNotInstanceOf(Order::class, $this->orderRepository->find($turnOneOrder->id));
        $this->assertNotInstanceOf(Order::class, $this->orderRepository->find($turnTwoOrder->id));
        $this->assertNotInstanceOf(Order::class, $this->orderRepository->find($turnThreeOrder->id));

        $reloadedPlayer = $this->reloadPlayer($player);
        $this->assertSame(['agriculture'], $reloadedPlayer->advances);
    }

    #[Test]
    public function erasingOnlyTheRequestedWindowLeavesOtherOrdersIntact(): void
    {
        $player = $this->createPlayer();

        $turnOneOrder = ($this->sellDirectHandler)(new SellDirect($player->id, $this->intents(['pottery']), 1));
        $turnTwoOrder = ($this->sellDirectHandler)(new SellDirect($player->id, $this->intents(['democracy']), 2));

        ($this->eraseOrdersHandler)(new EraseOrders($player->id, [2]));

        $this->assertNotInstanceOf(Order::class, $this->orderRepository->find($turnTwoOrder->id));

        $reloadedTurnOneOrder = $this->orderRepository->find($turnOneOrder->id);
        $this->assertInstanceOf(Order::class, $reloadedTurnOneOrder);
        $this->assertSame(OrderStatus::Validated, $reloadedTurnOneOrder->status);

        $this->assertSame(['pottery'], $this->reloadPlayer($player)->advances);
    }

    #[Test]
    public function erasingAValidatedOrderWithAGiftLineDisownsTheGiftedAdvanceToo(): void
    {
        $player = $this->createPlayer();

        $order = ($this->sellDirectHandler)(new SellDirect($player->id, [new LineIntent('anatomy', gift: 'astronavigation')], 1));

        $this->assertSame(['anatomy', 'astronavigation'], $order->keys());
        $this->assertSame(['anatomy', 'astronavigation'], $this->reloadPlayer($player)->advances);

        ($this->eraseOrdersHandler)(new EraseOrders($player->id, [1]));

        $this->assertNotInstanceOf(Order::class, $this->orderRepository->find($order->id));
        $this->assertSame([], $this->reloadPlayer($player)->advances);
    }

    #[Test]
    public function erasingAValidatedOrderWithAnOptionAllocationDropsItFromOptionCredits(): void
    {
        $player = $this->createPlayer();

        $order = ($this->sellDirectHandler)(new SellDirect(
            $player->id,
            [new LineIntent('monument', allocation: ['craft' => 10, 'science' => 10])],
            1,
        ));

        $this->assertSame(['craft' => 10, 'science' => 10], $this->shopConnector->buyerFor($this->reloadPlayer($player))->electiveCredits);

        ($this->eraseOrdersHandler)(new EraseOrders($player->id, [1]));

        $this->assertNotInstanceOf(Order::class, $this->orderRepository->find($order->id));
        $this->assertSame([], $this->shopConnector->buyerFor($this->reloadPlayer($player))->electiveCredits);
    }

    #[Test]
    public function erasingWithNoWindowsIsANoOp(): void
    {
        $player = $this->createPlayer();
        $order = $this->createPendingOrder($player, 1, ['pottery']);

        ($this->eraseOrdersHandler)(new EraseOrders($player->id, []));

        $this->assertInstanceOf(Order::class, $this->orderRepository->find($order->id));
    }

    #[Test]
    public function erasingOrdersPublishesPlayerUpdatedThenOrderUpdatedOnTheGameTopic(): void
    {
        $player = $this->createPlayer();
        $this->createPendingOrder($player, 1, ['pottery']);

        ($this->eraseOrdersHandler)(new EraseOrders($player->id, [1]));

        $this->assertSame(['player-updated', 'order-updated'], $this->hub->eventNames());
        $topic = 'empires/game/'.$player->game->id;
        $this->assertSame([$topic, $topic], $this->hub->topics());
    }

    #[Test]
    public function erasingWithNoWindowsPublishesNothing(): void
    {
        $player = $this->createPlayer();
        ($this->sellDirectHandler)(new SellDirect($player->id, $this->intents(['pottery']), 1));
        $publishedBySale = $this->hub->eventNames();

        ($this->eraseOrdersHandler)(new EraseOrders($player->id, []));

        $this->assertSame(['order-updated', 'player-updated'], $publishedBySale);
        $this->assertSame($publishedBySale, $this->hub->eventNames());
    }

    #[Test]
    public function erasingWindowsWithoutAMatchingOrderPublishesNothing(): void
    {
        $player = $this->createPlayer();
        ($this->sellDirectHandler)(new SellDirect($player->id, $this->intents(['pottery']), 1));
        $publishedBySale = $this->hub->eventNames();

        ($this->eraseOrdersHandler)(new EraseOrders($player->id, [2, 3]));

        $this->assertSame(['order-updated', 'player-updated'], $publishedBySale);
        $this->assertSame($publishedBySale, $this->hub->eventNames());
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

    /** @param list<string> $slugs */
    private function createPendingOrder(Player $player, int $turn, array $slugs): Order
    {
        $order = new Order($player, $turn);
        $order->replaceLines(array_map(static fn (string $slug): OrderLine => new OrderLine($slug, 0), $slugs));
        $this->entityManager->persist($order);
        $this->entityManager->flush();

        return $order;
    }

    private function reloadPlayer(Player $player): Player
    {
        $this->entityManager->clear();
        $reloaded = $this->entityManager->find(Player::class, $player->id);
        $this->assertInstanceOf(Player::class, $reloaded);

        return $reloaded;
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
