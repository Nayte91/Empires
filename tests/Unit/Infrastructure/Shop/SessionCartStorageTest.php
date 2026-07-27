<?php

declare(strict_types=1);

namespace App\Tests\Unit\Infrastructure\Shop;

use App\Infrastructure\Shop\SessionCartStorage;
use Userforged\ShopEngine\Cart;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Uid\Uuid;

final class SessionCartStorageTest extends TestCase
{
    #[Test]
    public function loadReturnsEmptyCartByDefault(): void
    {
        $storage = $this->makeStorage();

        $cart = $storage->load((string) Uuid::v4());

        $this->assertTrue($cart->isEmpty());
    }

    #[Test]
    public function saveThenLoadRestoresItems(): void
    {
        $storage = $this->makeStorage();
        $playerId = (string) Uuid::v4();
        $cart = new Cart();
        $cart->add('pottery');
        $cart->add('agriculture');

        $storage->save($playerId, $cart);

        $restored = $storage->load($playerId);

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
        $legacyStorage = new SessionCartStorage($requestStack);

        $restored = $legacyStorage->load($playerId->toRfc4122());

        $this->assertSame(['pottery', 'agriculture'], $restored->keys());
        $this->assertNull($restored->items[0]->gift);
    }

    #[Test]
    public function differentPlayerIdsHaveIndependentCarts(): void
    {
        $storage = $this->makeStorage();
        $firstPlayerId = (string) Uuid::v4();
        $secondPlayerId = (string) Uuid::v4();

        $firstCart = new Cart();
        $firstCart->add('pottery');
        $storage->save($firstPlayerId, $firstCart);

        $secondCart = new Cart();
        $secondCart->add('democracy');
        $storage->save($secondPlayerId, $secondCart);

        $this->assertSame(['pottery'], $storage->load($firstPlayerId)->keys());
        $this->assertSame(['democracy'], $storage->load($secondPlayerId)->keys());
    }

    #[Test]
    public function clearRemovesStoredCart(): void
    {
        $storage = $this->makeStorage();
        $playerId = (string) Uuid::v4();
        $cart = new Cart();
        $cart->add('pottery');
        $storage->save($playerId, $cart);

        $storage->clear($playerId);

        $this->assertTrue($storage->load($playerId)->isEmpty());
    }

    private function makeStorage(): SessionCartStorage
    {
        $requestStack = new RequestStack();
        $request = new Request();
        $request->setSession(new Session(new MockArraySessionStorage()));
        $requestStack->push($request);

        return new SessionCartStorage($requestStack);
    }
}
