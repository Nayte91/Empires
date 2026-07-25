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

        $cart = $repository->findOrCreate((string) Uuid::v4());

        $this->assertTrue($cart->isEmpty());
    }

    #[Test]
    public function saveThenFindOrCreateRestoresItems(): void
    {
        $repository = $this->makeRepository();
        $playerId = (string) Uuid::v4();
        $cart = new Cart();
        $cart->add('pottery');
        $cart->add('agriculture');

        $repository->save($playerId, $cart);

        $restored = $repository->findOrCreate($playerId);

        $this->assertSame(['pottery', 'agriculture'], $restored->keys());
    }

    #[Test]
    public function aSessionWrittenBeforeLineIntentDeserializesAsBareIntents(): void
    {
        $playerId = Uuid::v4();

        $requestStack = new RequestStack();
        $request = new Request();
        $session = new Session(new MockArraySessionStorage());
        $session->set('empires.shop.cart.'.$playerId->toRfc4122(), ['pottery', 'agriculture']);
        $request->setSession($session);
        $requestStack->push($request);
        $legacyRepository = new CartRepository($requestStack);

        $restored = $legacyRepository->findOrCreate($playerId->toRfc4122());

        $this->assertSame(['pottery', 'agriculture'], $restored->keys());
        $this->assertNull($restored->items[0]->gift);
    }

    #[Test]
    public function differentPlayerIdsHaveIndependentCarts(): void
    {
        $repository = $this->makeRepository();
        $firstPlayerId = (string) Uuid::v4();
        $secondPlayerId = (string) Uuid::v4();

        $firstCart = new Cart();
        $firstCart->add('pottery');
        $repository->save($firstPlayerId, $firstCart);

        $secondCart = new Cart();
        $secondCart->add('democracy');
        $repository->save($secondPlayerId, $secondCart);

        $this->assertSame(['pottery'], $repository->findOrCreate($firstPlayerId)->keys());
        $this->assertSame(['democracy'], $repository->findOrCreate($secondPlayerId)->keys());
    }

    #[Test]
    public function clearRemovesStoredCart(): void
    {
        $repository = $this->makeRepository();
        $playerId = (string) Uuid::v4();
        $cart = new Cart();
        $cart->add('pottery');
        $repository->save($playerId, $cart);

        $repository->clear($playerId);

        $this->assertTrue($repository->findOrCreate($playerId)->isEmpty());
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
