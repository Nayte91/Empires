<?php

declare(strict_types=1);

namespace App\Presentation\Shop;

use App\Rules\Ruleset\Advance;
use Userforged\ShopEngine\Dto\Product;

/**
 * The orders the catalogue can be shown in. An enum rather than a free string: a selector renders
 * its cases, and an unrecognised value cannot reach the sort at all.
 *
 * It lives in the application, not in the shop engine, for a reason that is not stylistic: the
 * engine's Product carries only the net cost, so it could express NetPrice but never ListPrice —
 * that number belongs to Advance, a type the package must not name.
 */
enum CatalogSort: string
{
    /**
     * What this buyer actually pays, discounts applied. Differs per player, and moves as their
     * discounts do. The kiosk re-sorts on it because the registry's list-price order and the
     * discounted prices contradict each other: most of the catalogue would otherwise show a price
     * that does not match its position.
     */
    case NetPrice = 'net_price';

    /** Alphabetical by advance name — the order a buyer scans when they know what they want. */
    case Name = 'name';

    /** What the advance costs on paper — the order the catalogue has always been rendered in. */
    case ListPrice = 'list_price';

    public function label(): string
    {
        return match ($this) {
            self::NetPrice => 'Net price',
            self::Name => 'Name',
            self::ListPrice => 'Raw price',
        };
    }

    /**
     * Ascending only, and deliberately so: no descending order has been asked for.
     *
     * // REFACTOR-WHEN: a sort has to run descending — that is the point to split direction out of
     * // the case, rather than adding NetPriceDesc and doubling the enum for every future key.
     *
     * @param array{advance: Advance, product: Product} $a
     * @param array{advance: Advance, product: Product} $b
     */
    public function compare(array $a, array $b): int
    {
        return match ($this) {
            self::ListPrice => $a['advance']->cost <=> $b['advance']->cost,
            self::NetPrice => $a['product']->netCost <=> $b['product']->netCost,
            self::Name => strcmp($a['advance']->name, $b['advance']->name),
        };
    }
}
