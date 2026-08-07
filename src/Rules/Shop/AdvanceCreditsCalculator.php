<?php

declare(strict_types=1);

namespace App\Rules\Shop;

use App\Rules\Ruleset\Advance;

/**
 * The display counterpart of AdvancePriceResolver: aggregates every credit a
 * buyer has earned for the Discounts molecule, rather than netting a single
 * product's price. Facet credits are SUMMED here (a player wants to see the
 * whole pile they've earned), where AdvancePriceResolver::facetCredits()
 * takes the MAX (a two-facet advance spends only its best credit). Both read
 * Mega Civilization's credit rules, but answer different questions — do not
 * fold one into the other.
 *
 * "Empty" — never earned — is deliberately not reported: it is readable off a
 * zero amount by the caller.
 */
final readonly class AdvanceCreditsCalculator
{
    /**
     * @param list<Entitlement> $entitlements
     * @param list<string>      $facets
     * @param list<string>      $ownedKeys
     * @param list<Advance>     $advances
     *
     * @return array{facets: array<string, array{amount: int, spent: bool}>, named: array<string, array{amount: int, spent: bool}>}
     */
    public function creditsFor(array $entitlements, array $facets = [], array $ownedKeys = [], array $advances = []): array
    {
        $facetAmounts = array_fill_keys($facets, 0);
        $namedAmounts = [];

        foreach ($entitlements as $entitlement) {
            if (\in_array($entitlement->scope, $facets, true)) {
                $facetAmounts[$entitlement->scope] += $entitlement->value;

                continue;
            }

            $namedAmounts[$entitlement->scope] = ($namedAmounts[$entitlement->scope] ?? 0) + $entitlement->value;
        }

        $facetCredits = [];
        foreach ($facetAmounts as $facet => $amount) {
            $facetCredits[$facet] = ['amount' => $amount, 'spent' => $this->isFacetSpent($facet, $ownedKeys, $advances)];
        }

        $namedCredits = [];
        foreach ($namedAmounts as $scope => $amount) {
            $namedCredits[$scope] = ['amount' => $amount, 'spent' => \in_array($scope, $ownedKeys, true)];
        }

        return ['facets' => $facetCredits, 'named' => $namedCredits];
    }

    /**
     * @param list<string>  $ownedKeys
     * @param list<Advance> $advances
     */
    private function isFacetSpent(string $facet, array $ownedKeys, array $advances): bool
    {
        $carriers = array_filter($advances, static fn (Advance $advance): bool => \in_array($facet, $advance->facets, true));

        if ([] === $carriers) {
            return false;
        }

        return array_all($carriers, static fn ($advance): bool => \in_array($advance->key, $ownedKeys, true));
    }
}
