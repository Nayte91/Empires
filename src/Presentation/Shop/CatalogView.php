<?php

declare(strict_types=1);

namespace App\Presentation\Shop;

/**
 * The catalogue's presentation as one of the two configurations its callers actually use — not
 * three independent switches a caller could set by name. Three flags in the wild (locked, sort,
 * remainingBudget) landing on only two combinations is what a view-model in denial looks like;
 * this closes the constructor so nothing else is expressible.
 */
final readonly class CatalogView
{
    private function __construct(
        public bool $locked,
        public CatalogSort $sort,
        public ?int $remainingBudget,
    ) {}

    /** The kiosk shop: locked per the turn, net-price order, over-budget tiles disabled. */
    public static function kiosk(bool $locked, ?int $remainingBudget): self
    {
        return new self($locked, CatalogSort::NetPrice, $remainingBudget);
    }

    /** The operator POS: never locked, list-price order, no budget effect. */
    public static function pos(): self
    {
        return new self(false, CatalogSort::ListPrice, null);
    }
}
