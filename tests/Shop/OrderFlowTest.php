<?php

declare(strict_types=1);

namespace App\Tests\Shop;

use App\Entity\GameSession;
use App\Entity\Player;
use App\Game\AdvanceCatalog;
use App\Repository\OrderRepository;
use App\Shop\Cart;
use App\Shop\CartRepository;
use App\Shop\OrderStatus;
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

final class OrderFlowTest extends WebTestCase
{
    private EntityManagerInterface $entityManager;
    private CartRepository $cartRepository;
    private OrderRepository $orderRepository;
    private OrderSubmitter $orderSubmitter;
    private OrderValidator $orderValidator;

    protected function setUp(): void
    {
        self::bootKernel();

        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->orderRepository = self::getContainer()->get(OrderRepository::class);

        // CartRepository, OrderSubmitter and OrderValidator have no other consumer yet
        // (controllers/Live Components land in later plan steps), so the compiled
        // container inlines them and they cannot be fetched directly. Build them here
        // from the shared RequestStack / EntityManager / OrderRepository instances.
        $requestStack = self::getContainer()->get(RequestStack::class);
        $request = new Request();
        $request->setSession(new Session(new MockArraySessionStorage()));
        $requestStack->push($request);

        $this->cartRepository = new CartRepository($requestStack);
        $this->orderSubmitter = new OrderSubmitter($this->entityManager, $this->cartRepository, $this->orderRepository, new NullHub());
        $this->orderValidator = new OrderValidator(
            $this->entityManager,
            self::getContainer()->get(AdvanceCatalog::class),
            new PriceCalculator(),
            new NullHub(),
        );
    }

    #[Test]
    public function submitWithEmptyCartThrowsDomainException(): void
    {
        $player = $this->createPlayer();

        $this->expectException(\DomainException::class);

        $this->orderSubmitter->submit($player);
    }

    #[Test]
    public function submitCreatesPendingOrderWithSlugsAndClearsCart(): void
    {
        $player = $this->createPlayer();
        $this->addToCart($player, 'pottery', 'agriculture');

        $order = $this->orderSubmitter->submit($player);

        self::assertSame(OrderStatus::Pending, $order->status);
        self::assertSame(['pottery', 'agriculture'], $order->lines);
        self::assertTrue($this->cartRepository->findOrCreate($player->id)->isEmpty());
    }

    #[Test]
    public function resubmitReplacesLinesOnSameOrderAndKeepsUniqueRow(): void
    {
        $player = $this->createPlayer();
        $this->addToCart($player, 'pottery');
        $firstOrder = $this->orderSubmitter->submit($player);

        $this->addToCart($player, 'democracy');
        $secondOrder = $this->orderSubmitter->submit($player);

        self::assertSame($firstOrder->id->toRfc4122(), $secondOrder->id->toRfc4122());
        self::assertSame(['democracy'], $secondOrder->lines);
        self::assertSame(1, $this->orderRepository->count([
            'player' => $player,
            'turn' => $player->game->currentTurn,
        ]));
    }

    #[Test]
    public function validateFreezesLinesAndOwnsAdvances(): void
    {
        $player = $this->createPlayer();
        $player->ownAdvances(['agriculture']);
        $this->entityManager->flush();

        $this->addToCart($player, 'democracy');
        $order = $this->orderSubmitter->submit($player);

        $this->orderValidator->validate($order);

        self::assertSame(OrderStatus::Validated, $order->status);
        self::assertSame([['key' => 'democracy', 'netCost' => 200]], $order->lines);
        self::assertSame(200, $order->total);
        self::assertContains('democracy', $player->advances);
    }

    #[Test]
    public function submitAfterValidationOfTheTurnThrowsDomainException(): void
    {
        $player = $this->createPlayer();
        $this->addToCart($player, 'pottery');
        $order = $this->orderSubmitter->submit($player);
        $this->orderValidator->validate($order);

        $this->addToCart($player, 'democracy');

        $this->expectException(\DomainException::class);

        $this->orderSubmitter->submit($player);
    }

    #[Test]
    public function submitWithAlreadyOwnedAdvanceInCartThrowsDomainException(): void
    {
        $player = $this->createPlayer();
        $player->ownAdvances(['pottery']);
        $this->entityManager->flush();

        $this->addToCart($player, 'pottery');

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessageMatches('/pottery/');

        $this->orderSubmitter->submit($player);
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

    private function addToCart(Player $player, string ...$slugs): void
    {
        $cart = new Cart();

        foreach ($slugs as $slug) {
            $cart->add($slug);
        }

        $this->cartRepository->save($player->id, $cart);
    }
}
