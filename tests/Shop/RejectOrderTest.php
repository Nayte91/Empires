<?php

declare(strict_types=1);

namespace App\Tests\Shop;

use App\Entity\GameSession;
use App\Entity\Order;
use App\Entity\Player;
use App\Game\AdvanceCatalog;
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
use App\Shop\Promotion\OptionCredits;
use App\Shop\Promotion\PromotionEngine;
use App\Shop\Service\LineQuoter;
use App\Shop\Service\OrderValidator;
use App\Shop\Service\PriceCalculator;
use App\Tests\Support\Mercure\NullHub;
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

    protected function setUp(): void
    {
        self::bootKernel();

        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->orderRepository = self::getContainer()->get(OrderRepository::class);
        $playerRepository = self::getContainer()->get(PlayerRepository::class);
        $advanceCatalog = self::getContainer()->get(AdvanceCatalog::class);

        // Same convention as OrderFlowTest/DirectSaleTest: these handlers have no
        // other consumer yet, so the compiled container inlines them and they
        // cannot be fetched directly. Built here from the shared EntityManager/
        // OrderRepository/PlayerRepository/AdvanceCatalog instances.
        $lineQuoter = new LineQuoter($advanceCatalog, new PriceCalculator(), new PromotionEngine(), new OptionCredits($this->orderRepository));
        $shopOrderStateMachine = ShopOrderStateMachine::create();
        $eventBus = self::getContainer()->get(ShopEventPublisher::class);
        $shopConnector = new ShopConnector($this->orderRepository);
        $this->submitOrderHandler = new SubmitOrderHandler(
            $this->entityManager,
            $this->orderRepository,
            $playerRepository,
            new NullHub(),
            $lineQuoter,
            $shopOrderStateMachine,
            $eventBus,
            $shopConnector,
        );
        $this->orderValidator = new OrderValidator(
            $this->entityManager,
            $lineQuoter,
            new NullHub(),
            $shopOrderStateMachine,
            $shopConnector,
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
            new NullHub(),
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

        self::assertSame(OrderStatus::Rejected, $order->status);
        self::assertSame(['pottery'], $order->keys());
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

        self::assertNotInstanceOf(Order::class, $this->orderRepository->findOneByPlayerAndWindow($player, $player->game->currentTurn));
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

        self::assertSame(OrderStatus::Pending, $order->status);
        self::assertSame(['democracy'], $order->keys());
    }

    #[Test]
    public function sellingDirectOntoARejectedSlotReopensThenValidatesTheOrder(): void
    {
        $player = $this->createPlayer();
        ($this->submitOrderHandler)(new SubmitOrder($player->id, $this->intents(['pottery']), $player->game->currentTurn));
        ($this->rejectOrderHandler)(new RejectOrder($player->id, $player->game->currentTurn));

        $order = ($this->sellDirectHandler)(new SellDirect($player->id, $this->intents(['democracy']), $player->game->currentTurn));

        self::assertSame(OrderStatus::Validated, $order->status);
        self::assertSame(['democracy'], $order->keys());
        self::assertContains('democracy', $player->advances);
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
