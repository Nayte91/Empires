<?php

declare(strict_types=1);

namespace Userforged\ShopEngine\Tests\Support;

use Userforged\ShopEngine\BuyerInterface;
use Userforged\ShopEngine\PriceResolverInterface;
use Userforged\ShopEngine\ProductInterface;

/**
 * A resolver whose answer is decided by the test, not by any pricing rule —
 * proves the engine only ever consumes whatever PriceResolverInterface
 * returns. Falls back to the product's raw cost for any key the test didn't
 * override.
 */
final readonly class FakePriceResolver implements PriceResolverInterface
{
    /** @param array<string, int> $pricesByKey */
    public function __construct(
        private array $pricesByKey = [],
    ) {}

    public function resolve(ProductInterface $product, BuyerInterface $buyer): int
    {
        return $this->pricesByKey[$product->key] ?? $product->cost;
    }
}
