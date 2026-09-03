<?php

declare(strict_types=1);

namespace App\Presentation\Shop;

/**
 * The catalogue's presentation as one of the two configurations its callers actually use — not
 * three independent switches a caller could set by name. The kiosk's sort is the one flag a buyer
 * actually chooses, net price by default; the POS never offers that choice, so its constructor
 * fixes list-price order instead of accepting one. The constructor stays private: nothing else is
 * expressible.
 */
final readonly class CatalogView
{
    private function __construct(
        public bool $locked,
        public CatalogSort $sort,
        public ?int $remainingBudget,
    ) {}

    /** The kiosk shop: locked per the turn, buyer-chosen order, over-budget tiles disabled. */
    public static function kiosk(bool $locked, ?int $remainingBudget, CatalogSort $sort = CatalogSort::NetPrice): self
    {
        return new self($locked, $sort, $remainingBudget);
    }

    /** The operator POS: never locked, list-price order, no budget effect. */
    public static function pos(): self
    {
        return new self(false, CatalogSort::ListPrice, null);
    }
}
