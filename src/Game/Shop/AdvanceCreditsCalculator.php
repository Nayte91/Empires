<?php

declare(strict_types=1);

namespace App\Game\Shop;

use App\Game\Dto\Advance;

/**
 * The display counterpart of AdvancePriceResolver: aggregates every credit
 * granted by a player's owned advances for the Discounts molecule, rather
 * than netting a single product's price. Facet credits are SUMMED here
 * (a player wants to see the whole pile they've earned), where
 * AdvancePriceResolver::facetCredits() takes the MAX (a two-facet advance
 * spends only its best credit). Both read Mega Civilization's credit rules,
 * but answer different questions — do not fold one into the other.
 *
 * This used to be Userforged\ShopEngine\Service\PriceCalculator::creditsFor();
 * it moved here for the same reason AdvancePriceResolver did: it is a Mega
 * Civilization rule, not a shop-pattern one.
 */
final readonly class AdvanceCreditsCalculator
{
    /**
     * @param list<Advance>      $owned
     * @param array<string, int> $bonusCredits
     * @param list<string>       $facets
     *
     * @return array{facets: array<string, int>, named: array<string, int>}
     */
    public function creditsFor(array $owned, array $bonusCredits = [], array $facets = []): array
    {
        $facetCredits = [];

        foreach ($facets as $facet) {
            $facetCredits[$facet] = $this->sumCreditsFor($facet, $owned, $bonusCredits);
        }

        $named = [];

        foreach ($owned as $ownedAdvance) {
            foreach ($ownedAdvance->credits as $key => $value) {
                if (\in_array($key, $facets, true)) {
                    continue;
                }

                $named[$key] = ($named[$key] ?? 0) + $value;
            }
        }

        return ['facets' => $facetCredits, 'named' => $named];
    }

    /**
     * @param list<Advance>      $owned
     * @param array<string, int> $bonusCredits
     */
    private function sumCreditsFor(string $creditKey, array $owned, array $bonusCredits): int
    {
        $sum = 0;

        foreach ($owned as $ownedAdvance) {
            $sum += $ownedAdvance->credits[$creditKey] ?? 0;
        }

        return $sum + ($bonusCredits[$creditKey] ?? 0);
    }
}
