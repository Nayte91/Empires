<?php

declare(strict_types=1);

namespace App\Tests\Shop;

use App\Entity\GameSession;
use App\Entity\Order;
use App\Entity\Player;
use App\Game\AdvanceCatalog;
use App\Repository\OrderRepository;
use App\Shop\OrderStatus;
use App\Shop\Service\DirectSale;
use App\Shop\Service\OrderEraser;
use App\Shop\Service\OrderValidator;
use App\Shop\Service\PriceCalculator;
use App\Tests\Support\Mercure\NullHub;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class OrderEraserTest extends WebTestCase
{
    private EntityManagerInterface $entityManager;
    private OrderRepository $orderRepository;
    private DirectSale $directSale;
    private OrderEraser $orderEraser;

    protected function setUp(): void
    {
        self::bootKernel();

        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->orderRepository = self::getContainer()->get(OrderRepository::class);

        // DirectSale and OrderValidator have no other consumer yet, so the compiled
        // container inlines them and they cannot be fetched directly. Built here from
        // the shared EntityManager/OrderRepository, following DirectSaleTest's convention.
        // NullHub (registered for the real HubInterface under config/services.yaml
        // when@test) makes every publish() call in this flow a no-op — no network I/O
        // happens during the suite.
        $orderValidator = new OrderValidator(
            $this->entityManager,
            self::getContainer()->get(AdvanceCatalog::class),
            new PriceCalculator(),
            new NullHub(),
        );
        $this->directSale = new DirectSale($this->entityManager, $this->orderRepository, $orderValidator);
        $this->orderEraser = new OrderEraser($this->entityManager, $this->orderRepository, new NullHub());
    }

    #[Test]
    public function erasingAPendingOrderDeletesItAndTouchesNothingElse(): void
    {
        $player = $this->createPlayer();
        $order = $this->createPendingOrder($player, 1, ['pottery']);

        $this->orderEraser->erase($order);

        self::assertNull($this->orderRepository->find($order->id));
        self::assertSame([], $this->reloadPlayer($player)->advances);
    }

    #[Test]
    public function erasingAValidatedOrderCascadesLaterTurnsAndUncreditsTheirAdvancesOnly(): void
    {
        $player = $this->createPlayer();
        $player->ownAdvances(['agriculture']);
        $this->entityManager->flush();

        $turnOneOrder = $this->directSale->sell($player, ['pottery'], 1);
        $turnTwoOrder = $this->directSale->sell($player, ['democracy'], 2);
        $turnThreeOrder = $this->createPendingOrder($player, 3, ['law']);

        $this->orderEraser->erase($turnOneOrder);

        self::assertNull($this->orderRepository->find($turnOneOrder->id));
        self::assertNull($this->orderRepository->find($turnTwoOrder->id));
        self::assertNull($this->orderRepository->find($turnThreeOrder->id));

        $reloadedPlayer = $this->reloadPlayer($player);
        self::assertSame(['agriculture'], $reloadedPlayer->advances);
    }

    #[Test]
    public function erasingAValidatedOrderLeavesEarlierTurnsIntact(): void
    {
        $player = $this->createPlayer();

        $turnOneOrder = $this->directSale->sell($player, ['pottery'], 1);
        $turnTwoOrder = $this->directSale->sell($player, ['democracy'], 2);

        $this->orderEraser->erase($turnTwoOrder);

        self::assertNull($this->orderRepository->find($turnTwoOrder->id));

        $reloadedTurnOneOrder = $this->orderRepository->find($turnOneOrder->id);
        self::assertNotNull($reloadedTurnOneOrder);
        self::assertSame(OrderStatus::Validated, $reloadedTurnOneOrder->status);

        self::assertSame(['pottery'], $this->reloadPlayer($player)->advances);
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
        $order->replaceLines($slugs);
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
}
