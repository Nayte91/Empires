<?php

declare(strict_types=1);

namespace App\Shop;

/**
 * The Game→Shop seam for the generic "facet" concept (port): the shop's
 * Option promotion splits a budget across opaque facets whose meaning the
 * library never learns — the host maps its own taxonomy onto this interface
 * (see App\Game\Shop\ShopConnector::facets()).
 */
interface FacetProviderInterface
{
    /** @return list<string> */
    public function facets(): array;
}
