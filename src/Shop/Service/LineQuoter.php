<?php

declare(strict_types=1);

namespace App\Shop\Service;

use App\Shop\BuyerInterface;
use App\Shop\Dto\LineIntent;
use App\Shop\Dto\OrderLine;
use App\Shop\Exception\PromotionException;
use App\Shop\ProductInterface;
use App\Shop\ProductProviderInterface;
use App\Shop\Promotion\PromotionEngine;

final readonly class LineQuoter
{
    public function __construct(
        private ProductProviderInterface $productProvider,
        private PriceCalculator $priceCalculator,
        private PromotionEngine $engine,
    ) {}

    /**
     * @param list<LineIntent> $intents
     * @param list<string>     $facets  valid allocation facets, forwarded to PromotionEngine::apply()
     *
     * @return list<OrderLine>
     */
    public function quote(array $intents, BuyerInterface $buyer, array $facets = []): array
    {
        $advances = $this->advancesFor($intents);
        $owned = $this->ownedFor($buyer);
        $catalog = $this->productProvider->products();

        $lines = $this->priceCalculator->priceLines($advances, $owned, $buyer->electiveCredits);

        return $this->engine->apply($lines, $advances, $intents, $catalog, $this->catalogNetCosts($catalog, $owned), $buyer->ownedKeys, $facets);
    }

    /**
     * Same as quote(), but tolerates an incomplete/invalid Option allocation
     * instead of throwing — falls back to plain, unpromoted pricing for that
     * render pass. Used by cart/ticket preview getters, where the player may
     * still be mid-allocation; submit/checkout call quote() directly, so the
     * PromotionException guard still applies there.
     *
     * @param list<LineIntent> $intents
     * @param list<string>     $facets  valid allocation facets, forwarded to PromotionEngine::apply()
     *
     * @return list<OrderLine>
     */
    public function quotePreview(array $intents, BuyerInterface $buyer, array $facets = []): array
    {
        try {
            return $this->quote($intents, $buyer, $facets);
        } catch (PromotionException) {
            return $this->priceCalculator->priceLines($this->advancesFor($intents), $this->ownedFor($buyer), $buyer->electiveCredits);
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
    private function advancesFor(array $intents): array
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

    /** @return list<ProductInterface> */
    private function ownedFor(BuyerInterface $buyer): array
    {
        return $this->productProvider->productsByKeys($buyer->ownedKeys);
    }

    /**
     * @param list<ProductInterface> $catalog
     * @param list<ProductInterface> $owned
     *
     * @return array<string, int>
     */
    private function catalogNetCosts(array $catalog, array $owned): array
    {
        $netCosts = [];

        foreach ($catalog as $advance) {
            $netCosts[$advance->key] = $this->priceCalculator->netCost($advance, $owned);
        }

        return $netCosts;
    }
}
