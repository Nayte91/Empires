<?php

declare(strict_types=1);

namespace Userforged\ShopEngine\Service;

use Userforged\ShopEngine\BuyerInterface;
use Userforged\ShopEngine\Dto\OrderLine;
use Userforged\ShopEngine\PriceResolverInterface;
use Userforged\ShopEngine\ProductInterface;

final readonly class PriceCalculator
{
    // Starting credits granted by config/game/scenarios.yaml, and the 'payment' YAML key,
    // are intentionally out of scope for this v1 shop pricing. 'promotion' is handled
    // downstream by Userforged\ShopEngine\Promotion\PromotionEngine, not here.

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

    /**
     * Aggregates every credit granted by $owned, globally rather than against a single
     * priced product: facet credits sum across all owned (no per-product max), and
     * every non-facet credit key is treated as a named credit toward that product slug.
     *
     * @param list<ProductInterface> $owned
     * @param array<string, int>     $bonusCredits
     * @param list<string>           $facets       valid facets (the Game→Shop seam for the
     *                                             generic "facet" concept, see ShopConnector::facets())
     *
     * @return array{facets: array<string, int>, named: array<string, int>}
     */
    public function creditsFor(array $owned, array $bonusCredits = [], array $facets = []): array
    {
        $facetCredits = [];

        foreach ($facets as $facet) {
            $facetCredits[$facet] = $this->sumCreditsFor($facet, $owned, $bonusCredits);
        }

        $named = [];

        foreach ($owned as $ownedProduct) {
            /** @var array<string, int> $credits */
            $credits = $ownedProduct->credits;

            foreach ($credits as $key => $value) {
                if (\in_array($key, $facets, true)) {
                    continue;
                }

                $named[$key] = ($named[$key] ?? 0) + $value;
            }
        }

        return ['facets' => $facetCredits, 'named' => $named];
    }

    /**
     * @param list<ProductInterface> $owned
     * @param array<string, int>     $bonusCredits
     */
    private function sumCreditsFor(string $creditKey, array $owned, array $bonusCredits = []): int
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
