<?php

declare(strict_types=1);

namespace App\Game\Shop;

use App\Game\AdvanceCatalog;
use App\Game\Dto\Advance;
use Userforged\ShopEngine\BuyerInterface;
use Userforged\ShopEngine\PriceResolverInterface;
use Userforged\ShopEngine\ProductInterface;

/**
 * The Game→Shop seam for PriceResolverInterface: nets a product's cost
 * against the credits its buyer has already earned through owned advances —
 * the best (never the sum) of its facet credits, plus every named credit
 * keyed by the product's own key. This is Mega Civilization's "best facet +
 * named credits" rule, not a generic shop-pattern one, which is why it moved
 * here from the library's own Userforged\ShopEngine\Service\CreditPriceResolver.
 *
 * The instanceof guard below is a deliberate host-side coupling, not an
 * oversight: BuyerInterface dropped electiveCredits from its contract along
 * with this rule, but PlayerBuyer — the only BuyerInterface implementation
 * ShopConnector::buyerFor()/PlayerBuyerProvider ever construct — still
 * carries them. This resolver is registered as the host's sole
 * Userforged\ShopEngine\PriceResolverInterface adapter (config/services.yaml),
 * so it is entitled to know the concrete type it is always handed.
 */
final readonly class AdvancePriceResolver implements PriceResolverInterface
{
    public function __construct(
        private AdvanceCatalog $advanceCatalog,
    ) {}

    public function resolve(ProductInterface $product, BuyerInterface $buyer): int
    {
        if (!$buyer instanceof PlayerBuyer) {
            throw new \InvalidArgumentException(sprintf('%s can only price for a %s, got %s.', self::class, PlayerBuyer::class, $buyer::class));
        }

        $owned = array_values($this->advanceCatalog->getAdvancesByNames($buyer->ownedKeys));
        $bonusCredits = $buyer->electiveCredits;

        $net = $product->cost - $this->facetCredits($product, $owned, $bonusCredits) - $this->namedCredits($product, $owned, $bonusCredits);

        return max(0, $net);
    }

    /**
     * @param list<Advance>      $owned
     * @param array<string, int> $bonusCredits
     */
    private function facetCredits(ProductInterface $product, array $owned, array $bonusCredits): int
    {
        $best = 0;

        foreach ($product->facets as $facet) {
            $best = max($best, $this->sumCreditsFor($facet, $owned, $bonusCredits));
        }

        return $best;
    }

    /**
     * @param list<Advance>      $owned
     * @param array<string, int> $bonusCredits
     */
    private function namedCredits(ProductInterface $product, array $owned, array $bonusCredits): int
    {
        return $this->sumCreditsFor($product->key, $owned, $bonusCredits);
    }

    /**
     * @param list<Advance>      $owned
     * @param array<string, int> $bonusCredits
     */
    private function sumCreditsFor(string $creditKey, array $owned, array $bonusCredits): int
    {
        $sum = 0;

        foreach ($owned as $ownedAdvance) {
            $sum += $ownedAdvance->credits[$creditKey] ?? 0;
        }

        return $sum + ($bonusCredits[$creditKey] ?? 0);
    }
}
