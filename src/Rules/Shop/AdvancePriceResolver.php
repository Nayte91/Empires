<?php

declare(strict_types=1);

namespace App\Rules\Shop;

use Userforged\ShopEngine\BuyerInterface;
use Userforged\ShopEngine\PriceResolverInterface;
use Userforged\ShopEngine\ProductInterface;

/**
 * The Game→Shop seam for PriceResolverInterface: nets a product's cost
 * against the credits its buyer has already earned — the best (never the
 * sum) of its facet credits, plus every named credit keyed by the product's
 * own key. This is Mega Civilization's "best facet + named credits" rule,
 * not a generic shop-pattern one, which is why it moved here from the
 * library's own Userforged\ShopEngine\Service\CreditPriceResolver.
 *
 * The rule reads the buyer's entitlements opaquely by scope — it neither
 * knows nor cares whether a given Entitlement came from an owned advance, an
 * elective allocation, or the scenario's starting credits (see
 * ShopConnector::buyerFor()). All three enter the same per-scope sum before
 * the max/sum rule below applies.
 *
 * The instanceof guard below is a deliberate host-side coupling, not an
 * oversight: BuyerInterface dropped entitlements from its contract along
 * with this rule, but PlayerBuyer — the only BuyerInterface implementation
 * ShopConnector::buyerFor()/PlayerBuyerProvider ever construct — still
 * carries them. This resolver is registered as the host's sole
 * Userforged\ShopEngine\PriceResolverInterface adapter (config/services.yaml),
 * so it is entitled to know the concrete type it is always handed.
 */
final readonly class AdvancePriceResolver implements PriceResolverInterface
{
    public function resolve(ProductInterface $product, BuyerInterface $buyer): int
    {
        if (!$buyer instanceof PlayerBuyer) {
            throw new \InvalidArgumentException(sprintf('%s can only price for a %s, got %s.', self::class, PlayerBuyer::class, $buyer::class));
        }

        $creditsByScope = $this->sumByScope($buyer->entitlements);

        $net = $product->cost - $this->facetCredits($product, $creditsByScope) - ($creditsByScope[$product->key] ?? 0);

        return max(0, $net);
    }

    /**
     * @param list<Entitlement> $entitlements
     *
     * @return array<string, int>
     */
    private function sumByScope(array $entitlements): array
    {
        $sums = [];

        foreach ($entitlements as $entitlement) {
            $sums[$entitlement->scope] = ($sums[$entitlement->scope] ?? 0) + $entitlement->value;
        }

        return $sums;
    }

    /** @param array<string, int> $creditsByScope */
    private function facetCredits(ProductInterface $product, array $creditsByScope): int
    {
        $best = 0;

        foreach ($product->facets as $facet) {
            $best = max($best, $creditsByScope[$facet] ?? 0);
        }

        return $best;
    }
}
