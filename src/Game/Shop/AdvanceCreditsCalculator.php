<?php

declare(strict_types=1);

namespace App\Game\Shop;

/**
 * The display counterpart of AdvancePriceResolver: aggregates every credit a
 * buyer has earned for the Discounts molecule, rather than netting a single
 * product's price. Facet credits are SUMMED here (a player wants to see the
 * whole pile they've earned), where AdvancePriceResolver::facetCredits()
 * takes the MAX (a two-facet advance spends only its best credit). Both read
 * Mega Civilization's credit rules, but answer different questions — do not
 * fold one into the other.
 *
 * Like the resolver, this reads entitlements opaquely by scope, regardless
 * of which of the three sources ShopConnector::buyerFor() composed them
 * from — a scope either matches a known facet or it doesn't; `source` never
 * enters the partition.
 *
 * This used to be Userforged\ShopEngine\Service\PriceCalculator::creditsFor();
 * it moved here for the same reason AdvancePriceResolver did: it is a Mega
 * Civilization rule, not a shop-pattern one.
 */
final readonly class AdvanceCreditsCalculator
{
    /**
     * @param list<Entitlement> $entitlements
     * @param list<string>      $facets
     *
     * @return array{facets: array<string, int>, named: array<string, int>}
     */
    public function creditsFor(array $entitlements, array $facets = []): array
    {
        $facetCredits = array_fill_keys($facets, 0);
        $named = [];

        foreach ($entitlements as $entitlement) {
            if (\in_array($entitlement->scope, $facets, true)) {
                $facetCredits[$entitlement->scope] += $entitlement->value;

                continue;
            }

            $named[$entitlement->scope] = ($named[$entitlement->scope] ?? 0) + $entitlement->value;
        }

        return ['facets' => $facetCredits, 'named' => $named];
    }
}
