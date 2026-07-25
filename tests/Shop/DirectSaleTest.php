<?php

declare(strict_types=1);

namespace App\Tests\Shop;

use App\Entity\GameSession;
use App\Entity\Order;
use App\Entity\Player;
use App\Game\Shop\ShopConnector;
use App\Repository\OrderRepository;
use App\Repository\PlayerRepository;
use App\Shop\Command\SellDirect;
use App\Shop\Command\SubmitOrder;
use App\Shop\CommandHandler\SellDirectHandler;
use App\Shop\CommandHandler\SubmitOrderHandler;
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
use App\Tests\Support\Mercure\NullHub;
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

    protected function setUp(): void
    {
        self::bootKernel();

        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->orderRepository = self::getContainer()->get(OrderRepository::class);
        $playerRepository = self::getContainer()->get(PlayerRepository::class);
        $productProvider = self::getContainer()->get(ProductProviderInterface::class);

        // SubmitOrderHandler, OrderValidator and SellDirectHandler have no other
        // consumer yet, so the compiled container inlines them and they cannot be
        // fetched directly. Build them here from the shared EntityManager/
        // OrderRepository/PlayerRepository/ProductProviderInterface instances, following
        // OrderFlowTest's convention.
        $lineQuoter = new LineQuoter($productProvider, new PriceCalculator(), new PromotionEngine());
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
        $orderValidator = new OrderValidator(
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
            $orderValidator,
            $lineQuoter,
            $shopOrderStateMachine,
            $eventBus,
            $shopConnector,
        );
    }

    #[Test]
    public function sellValidatesOrderImmediatelyAndOwnsAdvances(): void
    {
        $player = $this->createPlayer();
        $player->ownAdvances(['agriculture']);
        $this->entityManager->flush();

        $order = ($this->sellDirectHandler)(new SellDirect($player->id, $this->intents(['democracy', 'pottery']), $player->game->currentTurn));

        self::assertSame(OrderStatus::Validated, $order->status);
        self::assertEquals([new OrderLine('democracy', 200), new OrderLine('pottery', 50)], $order->lines);
        self::assertSame(250, $order->total);
        self::assertContains('democracy', $player->advances);
        self::assertContains('pottery', $player->advances);

        $this->entityManager->clear();
        $reloadedOrder = $this->orderRepository->find($order->id);
        self::assertInstanceOf(Order::class, $reloadedOrder);
        self::assertSame(OrderStatus::Validated, $reloadedOrder->status);
    }

    #[Test]
    public function sellingLibraryAndDemocracyDiscountsAndFreezesTheDemocracyLine(): void
    {
        $player = $this->createPlayer();

        $order = ($this->sellDirectHandler)(new SellDirect($player->id, $this->intents(['library', 'democracy']), $player->game->currentTurn));

        self::assertSame(400, $order->total);
        $democracyLine = $order->lines()[1];
        self::assertSame('democracy', $democracyLine->key);
        self::assertSame(180, $democracyLine->netCost);
        self::assertInstanceOf(AppliedPromotion::class, $democracyLine->promotion);
        self::assertSame(PromotionType::Discount, $democracyLine->promotion->type);
        self::assertSame('library', $democracyLine->promotion->source);
        self::assertSame(40, $democracyLine->promotion->amount);
    }

    #[Test]
    public function sellWithExplicitPastTurnCreatesAndValidatesTheOrderOnThatTurn(): void
    {
        $player = $this->createPlayer();
        $player->game->currentTurn = 3;
        $this->entityManager->flush();

        $order = ($this->sellDirectHandler)(new SellDirect($player->id, $this->intents(['pottery']), 1));

        self::assertSame(1, $order->turn);
        self::assertSame(OrderStatus::Validated, $order->status);
        self::assertContains('pottery', $player->advances);
    }

    #[Test]
    public function sellReusesExistingPendingOrderRow(): void
    {
        $player = $this->createPlayer();
        $pendingOrder = ($this->submitOrderHandler)(new SubmitOrder($player->id, $this->intents(['pottery']), $player->game->currentTurn));

        $order = ($this->sellDirectHandler)(new SellDirect($player->id, $this->intents(['democracy']), $player->game->currentTurn));

        self::assertSame($pendingOrder->id->toRfc4122(), $order->id->toRfc4122());
        self::assertSame(1, $this->orderRepository->count([
            'player' => $player,
            'turn' => $player->game->currentTurn,
        ]));
        self::assertEquals([new OrderLine('democracy', 220)], $order->lines);
    }

    #[Test]
    public function sellAfterValidationOfTheTurnThrowsOrderException(): void
    {
        $player = $this->createPlayer();
        ($this->sellDirectHandler)(new SellDirect($player->id, $this->intents(['pottery']), $player->game->currentTurn));

        $this->expectException(OrderException::class);

        ($this->sellDirectHandler)(new SellDirect($player->id, $this->intents(['democracy']), $player->game->currentTurn));
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
