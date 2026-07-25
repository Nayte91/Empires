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
use App\Shop\Command\EraseOrders;
use App\Shop\Command\SellDirect;
use App\Shop\CommandHandler\EraseOrdersHandler;
use App\Shop\CommandHandler\SellDirectHandler;
use App\Shop\Dto\LineIntent;
use App\Shop\Dto\OrderLine;
use App\Shop\Event\ShopEventPublisher;
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

final class OrderEraserTest extends WebTestCase
{
    private EntityManagerInterface $entityManager;
    private OrderRepository $orderRepository;
    private SellDirectHandler $sellDirectHandler;
    private EraseOrdersHandler $eraseOrdersHandler;

    protected function setUp(): void
    {
        self::bootKernel();

        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->orderRepository = self::getContainer()->get(OrderRepository::class);
        $playerRepository = self::getContainer()->get(PlayerRepository::class);

        // SellDirectHandler, OrderValidator and EraseOrdersHandler have no other
        // consumer yet, so the compiled container inlines them and they cannot be
        // fetched directly. Built here from the shared EntityManager/OrderRepository/
        // PlayerRepository, following DirectSaleTest's convention.
        // NullHub (registered for the real HubInterface under config/services.yaml
        // when@test) makes every publish() call in this flow a no-op — no network I/O
        // happens during the suite.
        $advanceCatalog = self::getContainer()->get(AdvanceCatalog::class);
        $lineQuoter = new LineQuoter($advanceCatalog, new PriceCalculator(), new PromotionEngine(), new OptionCredits($this->orderRepository));
        $shopOrderStateMachine = ShopOrderStateMachine::create();
        $eventBus = self::getContainer()->get(ShopEventPublisher::class);
        $shopConnector = new ShopConnector($this->orderRepository);
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
        $this->eraseOrdersHandler = new EraseOrdersHandler($this->entityManager, $this->orderRepository, $playerRepository, new NullHub(), $eventBus);
    }

    #[Test]
    public function erasingAPendingOrderDeletesItAndTouchesNothingElse(): void
    {
        $player = $this->createPlayer();
        $order = $this->createPendingOrder($player, 1, ['pottery']);

        ($this->eraseOrdersHandler)(new EraseOrders($player->id, [1]));

        self::assertNotInstanceOf(Order::class, $this->orderRepository->find($order->id));
        self::assertSame([], $this->reloadPlayer($player)->advances);
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

        self::assertNotInstanceOf(Order::class, $this->orderRepository->find($turnOneOrder->id));
        self::assertNotInstanceOf(Order::class, $this->orderRepository->find($turnTwoOrder->id));
        self::assertNotInstanceOf(Order::class, $this->orderRepository->find($turnThreeOrder->id));

        $reloadedPlayer = $this->reloadPlayer($player);
        self::assertSame(['agriculture'], $reloadedPlayer->advances);
    }

    #[Test]
    public function erasingOnlyTheRequestedWindowLeavesOtherOrdersIntact(): void
    {
        $player = $this->createPlayer();

        $turnOneOrder = ($this->sellDirectHandler)(new SellDirect($player->id, $this->intents(['pottery']), 1));
        $turnTwoOrder = ($this->sellDirectHandler)(new SellDirect($player->id, $this->intents(['democracy']), 2));

        ($this->eraseOrdersHandler)(new EraseOrders($player->id, [2]));

        self::assertNotInstanceOf(Order::class, $this->orderRepository->find($turnTwoOrder->id));

        $reloadedTurnOneOrder = $this->orderRepository->find($turnOneOrder->id);
        self::assertInstanceOf(Order::class, $reloadedTurnOneOrder);
        self::assertSame(OrderStatus::Validated, $reloadedTurnOneOrder->status);

        self::assertSame(['pottery'], $this->reloadPlayer($player)->advances);
    }

    #[Test]
    public function erasingAValidatedOrderWithAGiftLineDisownsTheGiftedAdvanceToo(): void
    {
        $player = $this->createPlayer();

        $order = ($this->sellDirectHandler)(new SellDirect($player->id, [new LineIntent('anatomy', gift: 'astronavigation')], 1));

        self::assertSame(['anatomy', 'astronavigation'], $order->keys());
        self::assertSame(['anatomy', 'astronavigation'], $this->reloadPlayer($player)->advances);

        ($this->eraseOrdersHandler)(new EraseOrders($player->id, [1]));

        self::assertNotInstanceOf(Order::class, $this->orderRepository->find($order->id));
        self::assertSame([], $this->reloadPlayer($player)->advances);
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

        $optionCredits = new OptionCredits($this->orderRepository);
        self::assertSame(['craft' => 10, 'science' => 10], $optionCredits->forPlayer($this->reloadPlayer($player)));

        ($this->eraseOrdersHandler)(new EraseOrders($player->id, [1]));

        self::assertNotInstanceOf(Order::class, $this->orderRepository->find($order->id));
        self::assertSame([], $optionCredits->forPlayer($this->reloadPlayer($player)));
    }

    #[Test]
    public function erasingWithNoWindowsIsANoOp(): void
    {
        $player = $this->createPlayer();
        $order = $this->createPendingOrder($player, 1, ['pottery']);

        ($this->eraseOrdersHandler)(new EraseOrders($player->id, []));

        self::assertInstanceOf(Order::class, $this->orderRepository->find($order->id));
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
        self::assertInstanceOf(Player::class, $reloaded);

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
