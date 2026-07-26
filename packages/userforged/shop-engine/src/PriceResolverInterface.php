<?php

declare(strict_types=1);

namespace Userforged\ShopEngine;

/**
 * The port where buyer-specific pricing plugs in (owned-product credits,
 * loyalty tiers, negotiated rates, …) — the library ships
 * Userforged\ShopEngine\Service\CreditPriceResolver as its default
 * implementation, but a host may swap in its own.
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
