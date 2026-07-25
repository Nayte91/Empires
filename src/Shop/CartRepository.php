<?php

declare(strict_types=1);

namespace App\Shop;

use App\Shop\Dto\LineIntent;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\SessionInterface;

final readonly class CartRepository
{
    private const string SESSION_KEY_PREFIX = 'empires.shop.cart.';

    public function __construct(
        private RequestStack $requestStack,
    ) {}

    public function findOrCreate(string $key): Cart
    {
        /** @var list<array{key: string, gift?: string, allocation?: array<string, int>}|string> $items */
        $items = $this->getSession()->get($this->sessionKey($key), []);

        $cart = new Cart();
        $cart->items = array_map(
            static fn (array|string $item): LineIntent => is_string($item) ? new LineIntent($item) : LineIntent::fromArray($item),
            $items,
        );

        return $cart;
    }

    public function save(string $key, Cart $cart): void
    {
        $this->getSession()->set(
            $this->sessionKey($key),
            array_map(static fn (LineIntent $item): array => $item->toArray(), $cart->items),
        );
    }

    public function clear(string $key): void
    {
        $this->getSession()->remove($this->sessionKey($key));
    }

    private function sessionKey(string $key): string
    {
        return self::SESSION_KEY_PREFIX.$key;
    }

    private function getSession(): SessionInterface
    {
        return $this->requestStack->getSession();
    }
}
