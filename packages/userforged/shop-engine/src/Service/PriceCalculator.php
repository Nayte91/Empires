<?php

declare(strict_types=1);

namespace Userforged\ShopEngine\Service;

use Userforged\ShopEngine\Dto\OrderLine;
use Userforged\ShopEngine\ProductInterface;

final class PriceCalculator
{
    // Starting credits granted by config/game/scenarios.yaml, and the 'payment' YAML key,
    // are intentionally out of scope for this v1 shop pricing. 'promotion' is handled
    // downstream by Userforged\ShopEngine\Promotion\PromotionEngine, not here.

    /**
     * @param list<ProductInterface> $owned
     * @param array<string, int>     $bonusCredits extra credits granted by validated
     *                                             Option allocations (Userforged\ShopEngine\Promotion\OptionCredits),
     *                                             merged into the facet totals alongside $owned
     */
    public function netCost(ProductInterface $product, array $owned, array $bonusCredits = []): int
    {
        $net = $product->cost - $this->facetCredits($product, $owned, $bonusCredits) - $this->namedCredits($product, $owned, $bonusCredits);

        return max(0, $net);
    }

    /**
     * @param list<ProductInterface> $products
     * @param list<ProductInterface> $owned
     * @param array<string, int>     $bonusCredits
     *
     * @return list<OrderLine>
     */
    public function priceLines(array $products, array $owned, array $bonusCredits = []): array
    {
        return array_map(
            fn (ProductInterface $product): OrderLine => new OrderLine($product->key, $this->netCost($product, $owned, $bonusCredits)),
            $products,
        );
    }

    /**
     * @param list<ProductInterface> $inOrder
     * @param list<ProductInterface> $owned
     * @param array<string, int>     $bonusCredits
     */
    public function orderTotal(array $inOrder, array $owned, array $bonusCredits = []): int
    {
        $total = 0;

        foreach ($inOrder as $product) {
            $total += $this->netCost($product, $owned, $bonusCredits);
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
    private function facetCredits(ProductInterface $product, array $owned, array $bonusCredits = []): int
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
    private function namedCredits(ProductInterface $product, array $owned, array $bonusCredits = []): int
    {
        return $this->sumCreditsFor($product->key, $owned, $bonusCredits);
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
