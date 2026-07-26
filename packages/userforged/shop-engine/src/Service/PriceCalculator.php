<?php

declare(strict_types=1);

namespace Userforged\ShopEngine\Service;

use Userforged\ShopEngine\BuyerInterface;
use Userforged\ShopEngine\Dto\OrderLine;
use Userforged\ShopEngine\PriceResolverInterface;
use Userforged\ShopEngine\ProductInterface;

/**
 * Turns resolved prices into order lines and totals. It decides no price of
 * its own: what a product costs a given buyer is PriceResolverInterface's
 * answer, and this class only guarantees the engine's invariant on top of it
 * — an integer, floored at zero, identical from catalog to quote to freeze.
 * Promotions are applied downstream by Promotion\PromotionEngine, never here.
 */
final readonly class PriceCalculator
{
    public function __construct(
        private PriceResolverInterface $priceResolver,
    ) {}

    /** The engine's own invariant on top of whatever the resolver decides: an integer, floored at zero. */
    public function netCost(ProductInterface $product, BuyerInterface $buyer): int
    {
        return max(0, $this->priceResolver->resolve($product, $buyer));
    }

    /**
     * @param list<ProductInterface> $products
     *
     * @return list<OrderLine>
     */
    public function priceLines(array $products, BuyerInterface $buyer): array
    {
        return array_map(
            fn (ProductInterface $product): OrderLine => new OrderLine($product->key, $this->netCost($product, $buyer)),
            $products,
        );
    }

    /** @param list<ProductInterface> $inOrder */
    public function orderTotal(array $inOrder, BuyerInterface $buyer): int
    {
        $total = 0;

        foreach ($inOrder as $product) {
            $total += $this->netCost($product, $buyer);
        }

        return $total;
    }
}
