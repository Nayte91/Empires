<?php

declare(strict_types=1);

namespace Userforged\ShopEngine\Promotion;

use Userforged\ShopEngine\Dto\LineIntent;
use Userforged\ShopEngine\Dto\OrderLine;
use Userforged\ShopEngine\Exception\PromotionException;
use Userforged\ShopEngine\ProductInterface;

// REFACTOR-WHEN: a 4th promotion action family joins the engine — split into one action class per PromotionType (the canonical promotion-engine shape), the engine keeping only composition and ordering.
final readonly class PromotionEngine
{
    /**
     * @param list<OrderLine>        $lines
     * @param list<ProductInterface> $inOrder
     * @param list<LineIntent>       $intents         buyer intents (gift choices), keyed by source product
     * @param list<ProductInterface> $catalog         full product catalog, needed to resolve gift candidates,
     *                                                and as a fallback to resolve a gift-appended line's own
     *                                                Option promotion (a gift-appended line is never present
     *                                                in $inOrder)
     * @param array<string, int>     $catalogNetCosts netCost of every catalog product, priced against the
     *                                                same $owned basis as $lines — required to stamp the
     *                                                gift line's "amount" (the cost it would have priced at)
     * @param list<string>           $ownedKeys       buyer's currently owned product keys
     * @param list<string>           $facets          valid Option allocation facets (see
     *                                                FacetProviderInterface::facets())
     *
     * @return list<OrderLine>
     */
    public function apply(
        array $lines,
        array $inOrder,
        array $intents = [],
        array $catalog = [],
        array $catalogNetCosts = [],
        array $ownedKeys = [],
        array $facets = [],
    ): array {
        $lines = $this->applyGifts($lines, $inOrder, $intents, $catalog, $catalogNetCosts, $ownedKeys);

        foreach ($lines as $line) {
            $product = $this->findProduct($inOrder, $line->key) ?? $this->findProduct($catalog, $line->key);
            if (!$product instanceof ProductInterface) {
                continue;
            }
            if (!$product->promotion instanceof ProductPromotion) {
                continue;
            }

            if ($product->promotion->option instanceof ElectiveBenefit) {
                $intent = $this->findIntent($intents, $line->key);
                $allocation = $intent instanceof LineIntent ? $intent->allocation : [];
                $lines = $this->applyOption($lines, $line->key, $product->promotion->option, $allocation, $facets);
            }

            $discount = $product->promotion->discount;

            if ([] === $discount) {
                continue;
            }

            $lines = $this->applyDiscount($lines, $line->key, $discount);
        }

        return $lines;
    }

    /**
     * Products a granting product's gift may offer: same facet as one of the
     * gift's keys, base cost below that facet's threshold, not already
     * owned, and not already present in the order.
     *
     * @param list<string>           $ownedKeys
     * @param list<string>           $inOrderKeys
     * @param list<ProductInterface> $catalog
     *
     * @return list<string>
     */
    public function giftCandidates(ProductInterface $granting, array $ownedKeys, array $inOrderKeys, array $catalog): array
    {
        $gift = $granting->promotion->gift ?? [];

        if ([] === $gift) {
            return [];
        }

        return array_values(array_map(
            static fn (ProductInterface $product): string => $product->key,
            array_filter(
                $catalog,
                fn (ProductInterface $product): bool => $this->isGiftCandidate($product, $gift, $ownedKeys, $inOrderKeys),
            ),
        ));
    }

    /**
     * Reconstructs intents (including gift choices) from a persisted/quoted
     * line set: a Gift-promoted line folds its key onto the source's intent
     * (as ->gift) but also gets its own emitted intent, so its own allocation
     * (if it also carries an Option promotion) survives a round-trip; an
     * Option-promoted line carries its own allocation straight through, so
     * re-quoting a persisted order revalidates (and re-stamps) the same
     * allocation rather than losing it.
     *
     * @param list<OrderLine> $lines
     *
     * @return list<LineIntent>
     */
    public function intentsFromLines(array $lines): array
    {
        $giftKeyBySource = [];

        foreach ($lines as $line) {
            if ($line->promotion instanceof AppliedPromotion && PromotionType::Gift === $line->promotion->type) {
                $giftKeyBySource[$line->promotion->source] = $line->key;
            }
        }

        $intents = [];

        foreach ($lines as $line) {
            $intents[] = new LineIntent(
                key: $line->key,
                gift: $giftKeyBySource[$line->key] ?? null,
                allocation: $line->promotion->allocation ?? [],
            );
        }

        return $intents;
    }

    /**
     * @param list<OrderLine>        $lines
     * @param list<ProductInterface> $inOrder
     * @param list<LineIntent>       $intents
     * @param list<ProductInterface> $catalog
     * @param array<string, int>     $catalogNetCosts
     * @param list<string>           $ownedKeys
     *
     * @return list<OrderLine>
     */
    private function applyGifts(array $lines, array $inOrder, array $intents, array $catalog, array $catalogNetCosts, array $ownedKeys): array
    {
        $inOrderKeys = array_map(static fn (ProductInterface $product): string => $product->key, $inOrder);

        foreach ($intents as $intent) {
            if (null === $intent->gift) {
                continue;
            }

            $source = $this->findProduct($inOrder, $intent->key);

            if (!$source instanceof ProductInterface) {
                continue;
            }
            if (!$source->promotion instanceof ProductPromotion) {
                continue;
            }
            if ([] === $source->promotion->gift) {
                continue;
            }

            $candidateKeys = $this->giftCandidates($source, $ownedKeys, $inOrderKeys, $catalog);

            if (!\in_array($intent->gift, $candidateKeys, true)) {
                throw PromotionException::invalidGift($intent->gift);
            }

            $lines[] = new OrderLine(
                key: $intent->gift,
                netCost: 0,
                promotion: new AppliedPromotion(PromotionType::Gift, $intent->key, $catalogNetCosts[$intent->gift] ?? 0),
            );
        }

        return $lines;
    }

    /**
     * @param array<string, int> $gift
     * @param list<string>       $ownedKeys
     * @param list<string>       $inOrderKeys
     */
    private function isGiftCandidate(ProductInterface $product, array $gift, array $ownedKeys, array $inOrderKeys): bool
    {
        $threshold = $this->giftThresholdFor($product, $gift);

        if (null === $threshold) {
            return false;
        }
        if ($product->cost >= $threshold) {
            return false;
        }
        if (\in_array($product->key, $ownedKeys, true)) {
            return false;
        }

        return !\in_array($product->key, $inOrderKeys, true);
    }

    /** @param array<string, int> $gift */
    private function giftThresholdFor(ProductInterface $product, array $gift): ?int
    {
        foreach ($product->facets as $facet) {
            if (isset($gift[$facet])) {
                // REFACTOR-WHEN: a 2nd product matching multiple gift facets with
                // different thresholds appears — currently the first matched facet wins.
                return $gift[$facet];
            }
        }

        return null;
    }

    /** @param list<ProductInterface> $inOrder */
    private function findProduct(array $inOrder, string $key): ?ProductInterface
    {
        foreach ($inOrder as $product) {
            if ($product->key === $key) {
                return $product;
            }
        }

        return null;
    }

    /** @param list<LineIntent> $intents */
    private function findIntent(array $intents, string $key): ?LineIntent
    {
        foreach ($intents as $intent) {
            if ($intent->key === $key) {
                return $intent;
            }
        }

        return null;
    }

    /**
     * Stamps the option-granting line itself with its own allocation once
     * valid — netCost is left untouched, the allocation only feeds future
     * pricing via Userforged\ShopEngine\Promotion\OptionCredits. If the line already
     * carries a promotion stamp (e.g. a gift-appended line, stamped Gift by
     * applyGifts()), that stamp's type/source/amount are preserved and only
     * the allocation is merged in: an unconditional replace would clobber the
     * Gift stamp Component\Cart::getChosenGiftFor() and intentsFromLines()'s
     * gift-detection rely on.
     *
     * @param list<OrderLine>    $lines
     * @param array<string, int> $allocation
     * @param list<string>       $facets
     *
     * @return list<OrderLine>
     */
    private function applyOption(array $lines, string $key, ElectiveBenefit $option, array $allocation, array $facets): array
    {
        $this->validateAllocation($key, $option, $allocation, $facets);

        foreach ($lines as $index => $line) {
            if ($line->key !== $key) {
                continue;
            }

            $existing = $line->promotion;
            $promotion = $existing instanceof AppliedPromotion
                ? new AppliedPromotion($existing->type, $existing->source, $existing->amount, $allocation)
                : new AppliedPromotion(PromotionType::Option, $key, allocation: $allocation);

            $lines[$index] = new OrderLine($line->key, $line->netCost, $promotion);

            break;
        }

        return $lines;
    }

    /**
     * Empty or not-yet-fully-spent allocations are a transient, expected state
     * while the buyer is still picking (allocationRequired); a wrong sum,
     * an unknown facet, or a non-multiple-of-step value can only come from
     * a bypassed client (invalidAllocation).
     *
     * @param array<string, int> $allocation
     * @param list<string>       $facets
     */
    private function validateAllocation(string $key, ElectiveBenefit $option, array $allocation, array $facets): void
    {
        if ([] === $allocation) {
            throw PromotionException::allocationRequired($key);
        }

        foreach ($allocation as $facet => $amount) {
            if (!\in_array($facet, $facets, true)) {
                throw PromotionException::invalidAllocation($key);
            }

            if ($amount < 0 || 0 !== $amount % $option->step) {
                throw PromotionException::invalidAllocation($key);
            }
        }

        $sum = array_sum($allocation);

        if ($sum < $option->budget) {
            throw PromotionException::allocationRequired($key);
        }

        if ($sum !== $option->budget) {
            throw PromotionException::invalidAllocation($key);
        }
    }

    /**
     * @param list<OrderLine>    $lines
     * @param array<string, int> $discount
     *
     * @return list<OrderLine>
     */
    private function applyDiscount(array $lines, string $source, array $discount): array
    {
        // Only the 'any' eligibility key is implemented today; facet-scoped keys
        // (e.g. an 'electronics' key restricting eligibility to a shop's
        // electronics-facet lines, or a 'science' key doing the same for a
        // game's science-facet lines) are left for later.
        $amount = $discount['any'] ?? null;

        if (null === $amount) {
            return $lines;
        }

        $targetIndex = $this->findMostExpensiveEligibleLine($lines, $source);

        if (null === $targetIndex) {
            return $lines;
        }

        $target = $lines[$targetIndex];
        $applied = min($amount, $target->netCost);

        $lines[$targetIndex] = new OrderLine(
            key: $target->key,
            netCost: $target->netCost - $applied,
            promotion: new AppliedPromotion(PromotionType::Discount, $source, $applied),
        );

        return $lines;
    }

    /**
     * The most expensive line other than the source's own — a line already at
     * netCost 0 is never eligible, since it has nothing left to discount.
     *
     * @param list<OrderLine> $lines
     */
    private function findMostExpensiveEligibleLine(array $lines, string $source): ?int
    {
        $bestIndex = null;
        $bestCost = 0;

        foreach ($lines as $index => $line) {
            if ($line->key === $source) {
                continue;
            }
            if (0 === $line->netCost) {
                continue;
            }
            if (null === $bestIndex || $line->netCost > $bestCost) {
                $bestIndex = $index;
                $bestCost = $line->netCost;
            }
        }

        return $bestIndex;
    }
}
