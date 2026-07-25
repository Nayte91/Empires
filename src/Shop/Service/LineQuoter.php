<?php

declare(strict_types=1);

namespace App\Shop\Service;

use App\Entity\Player;
use App\Game\AdvanceCatalog;
use App\Game\Dto\Advance;
use App\Shop\Dto\LineIntent;
use App\Shop\Dto\OrderLine;
use App\Shop\Exception\PromotionException;
use App\Shop\Promotion\OptionCredits;
use App\Shop\Promotion\PromotionEngine;

final readonly class LineQuoter
{
    public function __construct(
        private AdvanceCatalog $advanceCatalog,
        private PriceCalculator $priceCalculator,
        private PromotionEngine $engine,
        private OptionCredits $optionCredits,
    ) {}

    /**
     * @param list<LineIntent> $intents
     * @param list<string>     $buckets valid allocation categories, forwarded to PromotionEngine::apply()
     *
     * @return list<OrderLine>
     */
    public function quote(array $intents, Player $player, array $buckets = []): array
    {
        $advances = $this->advancesFor($intents);
        $owned = $this->ownedFor($player);
        $catalog = $this->advanceCatalog->getAdvances();

        $lines = $this->priceCalculator->priceLines($advances, $owned, $this->optionCredits->forPlayer($player));

        return $this->engine->apply($lines, $advances, $intents, $catalog, $this->catalogNetCosts($catalog, $owned), $player->advances, $buckets);
    }

    /**
     * Same as quote(), but tolerates an incomplete/invalid Option allocation
     * instead of throwing — falls back to plain, unpromoted pricing for that
     * render pass. Used by cart/ticket preview getters, where the player may
     * still be mid-allocation; submit/checkout call quote() directly, so the
     * PromotionException guard still applies there.
     *
     * @param list<LineIntent> $intents
     * @param list<string>     $buckets valid allocation categories, forwarded to PromotionEngine::apply()
     *
     * @return list<OrderLine>
     */
    public function quotePreview(array $intents, Player $player, array $buckets = []): array
    {
        try {
            return $this->quote($intents, $player, $buckets);
        } catch (PromotionException) {
            return $this->priceCalculator->priceLines($this->advancesFor($intents), $this->ownedFor($player), $this->optionCredits->forPlayer($player));
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
     * @return list<Advance>
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

        // getAdvancesByNames() returns a non-reindexed array (array_filter preserves
        // the original keys), so array_values is required before treating it as a list.
        return array_values($this->advanceCatalog->getAdvancesByNames($keys));
    }

    /** @return list<Advance> */
    private function ownedFor(Player $player): array
    {
        return array_values($this->advanceCatalog->getAdvancesByNames($player->advances));
    }

    /**
     * @param list<Advance> $catalog
     * @param list<Advance> $owned
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
