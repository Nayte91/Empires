<?php

declare(strict_types=1);

namespace Userforged\ShopEngine\Service;

use Userforged\ShopEngine\BuyerInterface;
use Userforged\ShopEngine\PriceResolverInterface;
use Userforged\ShopEngine\ProductInterface;
use Userforged\ShopEngine\ProductProviderInterface;

/**
 * Default Userforged\ShopEngine\PriceResolverInterface implementation: a
 * product's price nets out against the credits its buyer has already
 * earned — the best (not summed) of its facet credits, plus every named
 * credit keyed by the product's own key, floored at zero.
 */
final readonly class CreditPriceResolver implements PriceResolverInterface
{
    public function __construct(
        private ProductProviderInterface $productProvider,
    ) {}

    public function resolve(ProductInterface $product, BuyerInterface $buyer): int
    {
        $owned = $this->productProvider->productsByKeys($buyer->ownedKeys);
        $bonusCredits = $buyer->electiveCredits;

        $net = $product->cost - $this->facetCredits($product, $owned, $bonusCredits) - $this->namedCredits($product, $owned, $bonusCredits);

        return max(0, $net);
    }

    /**
     * @param list<ProductInterface> $owned
     * @param array<string, int>     $bonusCredits
     */
    private function facetCredits(ProductInterface $product, array $owned, array $bonusCredits): int
    {
        /** @var list<string> $facets */
        $facets = $product->facets;

        $best = 0;

        foreach ($facets as $facet) {
            $best = max($best, $this->sumCreditsFor($facet, $owned, $bonusCredits));
        }

        return $best;
    }

    /**
     * @param list<ProductInterface> $owned
     * @param array<string, int>     $bonusCredits
     */
    private function namedCredits(ProductInterface $product, array $owned, array $bonusCredits): int
    {
        return $this->sumCreditsFor($product->key, $owned, $bonusCredits);
    }

    /**
     * @param list<ProductInterface> $owned
     * @param array<string, int>     $bonusCredits
     */
    private function sumCreditsFor(string $creditKey, array $owned, array $bonusCredits): int
    {
        $sum = 0;

        foreach ($owned as $ownedProduct) {
            /** @var array<string, int> $credits */
            $credits = $ownedProduct->credits;
            $sum += $credits[$creditKey] ?? 0;
        }

        return $sum + ($bonusCredits[$creditKey] ?? 0);
    }
}
