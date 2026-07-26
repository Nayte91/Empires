<?php

declare(strict_types=1);

namespace Userforged\ShopEngine;

/**
 * The port where buyer-specific pricing plugs in (owned-product credits,
 * loyalty tiers, negotiated rates, …). The library ships no default
 * implementation — the host provides its own.
 *
 * The engine guarantees the invariant a resolve() implementation never has
 * to think about: the returned value is treated as an integer, floored at
 * zero, wherever Userforged\ShopEngine\Service\PriceCalculator consumes it.
 * The implementation only decides the value itself.
 */
interface PriceResolverInterface
{
    public function resolve(ProductInterface $product, BuyerInterface $buyer): int;
}
