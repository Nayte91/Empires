<?php

declare(strict_types=1);

namespace App\Tests\Shop;

use App\Shop\Cart;
use App\Shop\CartRepository;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Uid\Uuid;

final class CartRepositoryTest extends TestCase
{
    #[Test]
    public function findOrCreateReturnsEmptyCartByDefault(): void
    {
        $repository = $this->makeRepository();

        $cart = $repository->findOrCreate(Uuid::v4());

        self::assertTrue($cart->isEmpty());
    }

    #[Test]
    public function saveThenFindOrCreateRestoresItems(): void
    {
        $repository = $this->makeRepository();
        $playerId = Uuid::v4();
        $cart = new Cart();
        $cart->add('pottery');
        $cart->add('agriculture');

        $repository->save($playerId, $cart);

        $restored = $repository->findOrCreate($playerId);

        self::assertSame(['pottery', 'agriculture'], $restored->items);
    }

    #[Test]
    public function differentPlayerIdsHaveIndependentCarts(): void
    {
        $repository = $this->makeRepository();
        $firstPlayerId = Uuid::v4();
        $secondPlayerId = Uuid::v4();

        $firstCart = new Cart();
        $firstCart->add('pottery');
        $repository->save($firstPlayerId, $firstCart);

        $secondCart = new Cart();
        $secondCart->add('democracy');
        $repository->save($secondPlayerId, $secondCart);

        self::assertSame(['pottery'], $repository->findOrCreate($firstPlayerId)->items);
        self::assertSame(['democracy'], $repository->findOrCreate($secondPlayerId)->items);
    }

    #[Test]
    public function clearRemovesStoredCart(): void
    {
        $repository = $this->makeRepository();
        $playerId = Uuid::v4();
        $cart = new Cart();
        $cart->add('pottery');
        $repository->save($playerId, $cart);

        $repository->clear($playerId);

        self::assertTrue($repository->findOrCreate($playerId)->isEmpty());
    }

    private function makeRepository(): CartRepository
    {
        $requestStack = new RequestStack();
        $request = new Request();
        $request->setSession(new Session(new MockArraySessionStorage()));
        $requestStack->push($request);

        return new CartRepository($requestStack);
    }
}
