<?php

declare(strict_types=1);

namespace App\Shop;

use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\Uid\Uuid;

final readonly class CartRepository
{
    private const string SESSION_KEY_PREFIX = 'empires.shop.cart.';

    public function __construct(
        private RequestStack $requestStack,
    ) {}

    public function findOrCreate(Uuid $playerId): Cart
    {
        /** @var list<string> $items */
        $items = $this->getSession()->get($this->sessionKey($playerId), []);

        $cart = new Cart();
        $cart->items = $items;

        return $cart;
    }

    public function save(Uuid $playerId, Cart $cart): void
    {
        $this->getSession()->set($this->sessionKey($playerId), $cart->items);
    }

    public function clear(Uuid $playerId): void
    {
        $this->getSession()->remove($this->sessionKey($playerId));
    }

    private function sessionKey(Uuid $playerId): string
    {
        return self::SESSION_KEY_PREFIX.$playerId->toRfc4122();
    }

    private function getSession(): SessionInterface
    {
        return $this->requestStack->getSession();
    }
}
