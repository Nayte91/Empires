<?php

declare(strict_types=1);

namespace App\Tests\Shop;

use App\Entity\GameSession;
use App\Entity\Order;
use App\Entity\Player;
use App\Game\Shop\ShopConnector;
use App\Repository\OrderRepository;
use App\Repository\PlayerRepository;
use App\Shop\Command\RejectOrder;
use App\Shop\Command\SellDirect;
use App\Shop\Command\SubmitOrder;
use App\Shop\CommandHandler\RejectOrderHandler;
use App\Shop\CommandHandler\SellDirectHandler;
use App\Shop\CommandHandler\SubmitOrderHandler;
use App\Shop\Dto\LineIntent;
use App\Shop\Event\ShopEventPublisher;
use App\Shop\Exception\OrderException;
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
use Symfony\Component\Uid\Uuid;

final class RejectOrderTest extends WebTestCase
{
    private EntityManagerInterface $entityManager;
    private OrderRepository $orderRepository;
    private SubmitOrderHandler $submitOrderHandler;
    private SellDirectHandler $sellDirectHandler;
    private OrderValidator $orderValidator;
    private RejectOrderHandler $rejectOrderHandler;
    private RecordingHub $hub;

    protected function setUp(): void
    {
        self::bootKernel();

        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->orderRepository = self::getContainer()->get(OrderRepository::class);
        $playerRepository = self::getContainer()->get(PlayerRepository::class);
        $productProvider = self::getContainer()->get(ProductProviderInterface::class);
        $this->hub = self::getContainer()->get(RecordingHub::class);

        // Same convention as OrderFlowTest/DirectSaleTest, but here it's load-bearing:
        // these handlers are built by hand, not fetched from the container, so they run
        // on the guard-free ShopOrderStateMachine test double instead of the container's
        // registered shop_order workflow. The container's workflow carries
        // OrderWorkflowPolicy's guard, which blocks the reject transition unconditionally
        // in production — fetching the real handlers here would make every reject
        // assertion below fail. Built from the shared EntityManager/OrderRepository/
        // PlayerRepository/ProductProviderInterface instances.
        $lineQuoter = new LineQuoter($productProvider, new PriceCalculator(), new PromotionEngine());
        $shopOrderStateMachine = ShopOrderStateMachine::create();
        $eventBus = self::getContainer()->get(ShopEventPublisher::class);
        $shopConnector = new ShopConnector($this->orderRepository);
        $this->submitOrderHandler = new SubmitOrderHandler(
            $this->entityManager,
            $this->orderRepository,
            $playerRepository,
            $lineQuoter,
            $shopOrderStateMachine,
            $eventBus,
            $shopConnector,
        );
        $this->orderValidator = new OrderValidator(
            $this->entityManager,
            $lineQuoter,
            $shopOrderStateMachine,
            $shopConnector,
            $eventBus,
        );
        $this->sellDirectHandler = new SellDirectHandler(
            $this->entityManager,
            $this->orderRepository,
            $playerRepository,
            $this->orderValidator,
            $lineQuoter,
            $shopOrderStateMachine,
            $eventBus,
            $shopConnector,
        );
        $this->rejectOrderHandler = new RejectOrderHandler(
            $this->entityManager,
            $this->orderRepository,
            $playerRepository,
            $shopOrderStateMachine,
            $eventBus,
        );
    }

    #[Test]
    public function rejectingAPendingOrderMarksItRejectedAndKeepsItsLines(): void
    {
        $player = $this->createPlayer();
        $order = ($this->submitOrderHandler)(new SubmitOrder($player->id, $this->intents(['pottery']), $player->game->currentTurn));

        ($this->rejectOrderHandler)(new RejectOrder($player->id, $player->game->currentTurn));

        $this->assertSame(OrderStatus::Rejected, $order->status);
        $this->assertSame(['pottery'], $order->keys());
    }

    #[Test]
    public function rejectingAValidatedOrderThrowsOrderException(): void
    {
        $player = $this->createPlayer();
        $order = ($this->submitOrderHandler)(new SubmitOrder($player->id, $this->intents(['pottery']), $player->game->currentTurn));
        $this->orderValidator->validate($order);

        $this->expectException(OrderException::class);

        ($this->rejectOrderHandler)(new RejectOrder($player->id, $player->game->currentTurn));
    }

    #[Test]
    public function rejectingAMissingOrderIsANoOp(): void
    {
        $player = $this->createPlayer();

        ($this->rejectOrderHandler)(new RejectOrder($player->id, $player->game->currentTurn));

        $this->assertNotInstanceOf(Order::class, $this->orderRepository->findOneByPlayerAndWindow($player, $player->game->currentTurn));
    }

    #[Test]
    public function rejectingAnOrderForAnUnknownPlayerThrows(): void
    {
        $this->expectException(\RuntimeException::class);

        ($this->rejectOrderHandler)(new RejectOrder(Uuid::v7(), 1));
    }

    #[Test]
    public function submittingOntoARejectedSlotReopensItWithTheNewLines(): void
    {
        $player = $this->createPlayer();
        ($this->submitOrderHandler)(new SubmitOrder($player->id, $this->intents(['pottery']), $player->game->currentTurn));
        ($this->rejectOrderHandler)(new RejectOrder($player->id, $player->game->currentTurn));

        $order = ($this->submitOrderHandler)(new SubmitOrder($player->id, $this->intents(['democracy']), $player->game->currentTurn));

        $this->assertSame(OrderStatus::Pending, $order->status);
        $this->assertSame(['democracy'], $order->keys());
    }

    #[Test]
    public function sellingDirectOntoARejectedSlotReopensThenValidatesTheOrder(): void
    {
        $player = $this->createPlayer();
        ($this->submitOrderHandler)(new SubmitOrder($player->id, $this->intents(['pottery']), $player->game->currentTurn));
        ($this->rejectOrderHandler)(new RejectOrder($player->id, $player->game->currentTurn));

        $order = ($this->sellDirectHandler)(new SellDirect($player->id, $this->intents(['democracy']), $player->game->currentTurn));

        $this->assertSame(OrderStatus::Validated, $order->status);
        $this->assertSame(['democracy'], $order->keys());
        $this->assertContains('democracy', $player->advances);
    }

    #[Test]
    public function rejectingAPendingOrderPublishesOrderUpdatedOnTheGameTopic(): void
    {
        $player = $this->createPlayer();
        ($this->submitOrderHandler)(new SubmitOrder($player->id, $this->intents(['pottery']), $player->game->currentTurn));
        $this->hub->clear();

        ($this->rejectOrderHandler)(new RejectOrder($player->id, $player->game->currentTurn));

        $this->assertSame(['order-updated'], $this->hub->eventNames());
        $this->assertSame(['empires/game/'.$player->game->id], $this->hub->topics());
    }

    #[Test]
    public function rejectingAMissingOrderPublishesNothing(): void
    {
        $player = $this->createPlayer();
        ($this->submitOrderHandler)(new SubmitOrder($player->id, $this->intents(['pottery']), $player->game->currentTurn));
        $publishedBySubmit = $this->hub->eventNames();

        ($this->rejectOrderHandler)(new RejectOrder($player->id, $player->game->currentTurn + 1));

        $this->assertSame(['order-updated'], $publishedBySubmit);
        $this->assertSame($publishedBySubmit, $this->hub->eventNames());
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
