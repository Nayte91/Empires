<?php

declare(strict_types=1);

namespace Userforged\ShopEngine\Service;

use Userforged\ShopEngine\BuyerInterface;
use Userforged\ShopEngine\Dto\LineIntent;
use Userforged\ShopEngine\Dto\OrderLine;
use Userforged\ShopEngine\Exception\PromotionException;
use Userforged\ShopEngine\FacetProviderInterface;
use Userforged\ShopEngine\ProductInterface;
use Userforged\ShopEngine\ProductProviderInterface;
use Userforged\ShopEngine\Promotion\PromotionEngine;

final readonly class LineQuoter
{
    public function __construct(
        private ProductProviderInterface $productProvider,
        private PriceCalculator $priceCalculator,
        private PromotionEngine $engine,
        private FacetProviderInterface $facetProvider,
    ) {}

    /**
     * @param list<LineIntent> $intents
     *
     * @return list<OrderLine>
     */
    public function quote(array $intents, BuyerInterface $buyer): array
    {
        $products = $this->productsFor($intents);
        $catalog = $this->productProvider->products();

        $lines = $this->priceCalculator->priceLines($products, $buyer);

        return $this->engine->apply($lines, $products, $intents, $catalog, $this->catalogNetCosts($catalog, $buyer), $buyer->ownedKeys, $this->facetProvider->facets());
    }

    /**
     * Same as quote(), but tolerates an incomplete/invalid Option allocation
     * instead of throwing — falls back to plain, unpromoted pricing for that
     * render pass. Used by cart/ticket preview getters, where the buyer may
     * still be mid-allocation; submit/checkout call quote() directly, so the
     * PromotionException guard still applies there.
     *
     * @param list<LineIntent> $intents
     *
     * @return list<OrderLine>
     */
    public function quotePreview(array $intents, BuyerInterface $buyer): array
    {
        try {
            return $this->quote($intents, $buyer);
        } catch (PromotionException) {
            return $this->priceCalculator->priceLines($this->productsFor($intents), $buyer);
        }
    }

    /**
     * Reconstructs intents (including gift choices) from a persisted/quoted
     * line set — thin passthrough to PromotionEngine so callers needing this
     * don't have to take a PromotionEngine dependency of their own.
     *
     * @param list<OrderLine> $lines
     *
     * @return list<LineIntent>
     */
    public function intentsFromLines(array $lines): array
    {
        return $this->engine->intentsFromLines($lines);
    }

    /**
     * A gift-target key (referenced by another intent's ->gift) is excluded:
     * it's already appended as a zero-cost line by
     * PromotionEngine::applyGifts(), so letting it through here would price
     * it again as a full-price direct purchase.
     *
     * @param list<LineIntent> $intents
     *
     * @return list<ProductInterface>
     */
    private function productsFor(array $intents): array
    {
        $giftKeys = array_filter(
            array_map(static fn (LineIntent $intent): ?string => $intent->gift, $intents),
            static fn (?string $gift): bool => null !== $gift,
        );

        $keys = array_map(
            static fn (LineIntent $intent): string => $intent->key,
            array_values(array_filter(
                $intents,
                static fn (LineIntent $intent): bool => !\in_array($intent->key, $giftKeys, true),
            )),
        );

        return $this->productProvider->productsByKeys($keys);
    }

    /**
     * @param list<ProductInterface> $catalog
     *
     * @return array<string, int>
     */
    private function catalogNetCosts(array $catalog, BuyerInterface $buyer): array
    {
        $netCosts = [];

        foreach ($catalog as $product) {
            $netCosts[$product->key] = $this->priceCalculator->netCost($product, $buyer);
        }

        return $netCosts;
    }
}
