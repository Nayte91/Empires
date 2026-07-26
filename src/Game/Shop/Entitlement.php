<?php

declare(strict_types=1);

namespace App\Game\Shop;

/**
 * A single credit a buyer has earned, from any of the three sources
 * ShopConnector::buyerFor() composes: an owned advance, an elective
 * allocation, or the scenario's starting credits.
 *
 * `scope` is opaque, exactly like the facet/named-key duality it replaces:
 * it is compared against a product's facets *or* its own key — never
 * disambiguated here. `source` traces the origin only, for accountability —
 * it must never enter a computation, or a player could end up credited
 * without anyone being able to say why.
 */
final readonly class Entitlement
{
    public function __construct(
        public string $scope,
        public int $value,
        public string $source,
    ) {}
}
