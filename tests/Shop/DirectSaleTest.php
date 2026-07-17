<?php

declare(strict_types=1);

namespace App\Tests\Shop;

use App\Entity\Game;
use App\Entity\Player;
use App\Enumeration\OrderStatus;
use App\Repository\AdvanceRepository;
use App\Repository\OrderRepository;
use App\Shop\Cart;
use App\Shop\CartRepository;
use App\Shop\Service\DirectSale;
use App\Shop\Service\OrderSubmitter;
use App\Shop\Service\OrderValidator;
use App\Shop\Service\PriceCalculator;
use App\Tests\Support\Mercure\NullHub;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

final class DirectSaleTest extends WebTestCase
{
    private EntityManagerInterface $entityManager;
    private CartRepository $cartRepository;
    private OrderRepository $orderRepository;
    private OrderSubmitter $orderSubmitter;
    private DirectSale $directSale;

    protected function setUp(): void
    {
        self::bootKernel();

        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->orderRepository = self::getContainer()->get(OrderRepository::class);

        // CartRepository, OrderSubmitter, OrderValidator and DirectSale have no other
        // consumer yet, so the compiled container inlines them and they cannot be
        // fetched directly. Build them here from the shared RequestStack / EntityManager /
        // OrderRepository instances, following OrderFlowTest's convention.
        $requestStack = self::getContainer()->get(RequestStack::class);
        $request = new Request();
        $request->setSession(new Session(new MockArraySessionStorage()));
        $requestStack->push($request);

        $this->cartRepository = new CartRepository($requestStack);
        $this->orderSubmitter = new OrderSubmitter($this->entityManager, $this->cartRepository, $this->orderRepository, new NullHub());
        $orderValidator = new OrderValidator(
            $this->entityManager,
            self::getContainer()->get(AdvanceRepository::class),
            new PriceCalculator(),
            new NullHub(),
        );
        $this->directSale = new DirectSale($this->entityManager, $this->orderRepository, $orderValidator);
    }

    #[Test]
    public function sellValidatesOrderImmediatelyAndOwnsAdvances(): void
    {
        $player = $this->createPlayer();
        $player->ownAdvances(['agriculture']);
        $this->entityManager->flush();

        $order = $this->directSale->sell($player, ['democracy', 'pottery'], 250);

        self::assertSame(OrderStatus::Validated, $order->status);
        self::assertSame(
            [['key' => 'democracy', 'netCost' => 200], ['key' => 'pottery', 'netCost' => 50]],
            $order->lines,
        );
        self::assertSame(250, $order->total);
        self::assertSame(250, $order->money);
        self::assertContains('democracy', $player->advances);
        self::assertContains('pottery', $player->advances);

        $this->entityManager->clear();
        $reloadedOrder = $this->orderRepository->find($order->id);
        self::assertNotNull($reloadedOrder);
        self::assertSame(OrderStatus::Validated, $reloadedOrder->status);
    }

    #[Test]
    public function sellWithInsufficientMoneyThrowsAndPersistsNoOrder(): void
    {
        $player = $this->createPlayer();

        try {
            $this->directSale->sell($player, ['pottery'], 10);
            self::fail('Expected DomainException was not thrown.');
        } catch (\DomainException $exception) {
            self::assertStringContainsString('60', $exception->getMessage());
            self::assertStringContainsString('10', $exception->getMessage());
        }

        self::assertSame(0, $this->orderRepository->count([]));
    }

    #[Test]
    public function sellReusesExistingPendingOrderRow(): void
    {
        $player = $this->createPlayer();
        $this->addToCart($player, 'pottery');
        $pendingOrder = $this->orderSubmitter->submit($player);

        $order = $this->directSale->sell($player, ['democracy'], 220);

        self::assertSame($pendingOrder->id->toRfc4122(), $order->id->toRfc4122());
        self::assertSame(1, $this->orderRepository->count([
            'player' => $player,
            'turn' => $player->game->currentTurn,
        ]));
        self::assertSame([['key' => 'democracy', 'netCost' => 220]], $order->lines);
    }

    #[Test]
    public function sellAfterValidationOfTheTurnThrowsDomainException(): void
    {
        $player = $this->createPlayer();
        $this->directSale->sell($player, ['pottery'], 60);

        $this->expectException(\DomainException::class);

        $this->directSale->sell($player, ['democracy'], 220);
    }

    #[Test]
    public function findByGameAndTurnScopesToGameAndTurn(): void
    {
        $game = new Game();
        $this->entityManager->persist($game);
        $playerTurnOne = new Player($game, 'Alice');
        $this->entityManager->persist($playerTurnOne);
        $this->entityManager->flush();

        $this->directSale->sell($playerTurnOne, ['pottery'], 60);

        $game->currentTurn = 2;
        $playerTurnTwo = new Player($game, 'Bob');
        $this->entityManager->persist($playerTurnTwo);
        $this->entityManager->flush();

        $this->directSale->sell($playerTurnTwo, ['pottery'], 60);

        $otherGamePlayer = $this->createPlayer();
        $this->directSale->sell($otherGamePlayer, ['pottery'], 60);

        $turnTwoOrders = $this->orderRepository->findByGameAndTurn($game, 2);

        self::assertCount(1, $turnTwoOrders);
        self::assertSame(2, $turnTwoOrders[0]->turn);
        self::assertSame($playerTurnTwo->id->toRfc4122(), $turnTwoOrders[0]->player->id->toRfc4122());

        $turnOneOrders = $this->orderRepository->findByGameAndTurn($game, 1);

        self::assertCount(1, $turnOneOrders);
        self::assertSame(1, $turnOneOrders[0]->turn);
        self::assertSame($playerTurnOne->id->toRfc4122(), $turnOneOrders[0]->player->id->toRfc4122());
    }

    private function createPlayer(): Player
    {
        $game = new Game();
        $player = new Player($game, 'Alice');

        $this->entityManager->persist($game);
        $this->entityManager->persist($player);
        $this->entityManager->flush();

        return $player;
    }

    private function addToCart(Player $player, string ...$slugs): void
    {
        $cart = new Cart();

        foreach ($slugs as $slug) {
            $cart->add($slug);
        }

        $this->cartRepository->save($player->id, $cart);
    }
}
