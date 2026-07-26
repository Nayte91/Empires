<?php

declare(strict_types=1);

namespace App\Tests\Game\Shop;

use App\Game\Shop\AdvanceCreditsCalculator;
use App\Game\Shop\Entitlement;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class AdvanceCreditsCalculatorTest extends TestCase
{
    private const array FACETS = ['art', 'civic', 'craft', 'religion', 'science'];

    #[Test]
    public function creditsForWithNoEntitlementsReturnsAllZeroFacetsAndNoNamedCredits(): void
    {
        $credits = new AdvanceCreditsCalculator()->creditsFor([], self::FACETS);

        $this->assertSame(['art' => 0, 'civic' => 0, 'craft' => 0, 'religion' => 0, 'science' => 0], $credits['facets']);
        $this->assertSame([], $credits['named']);
    }

    #[Test]
    public function creditsForAggregatesOneOwnedAdvancesEntitlements(): void
    {
        $agriculture = $this->entitlementsFor('advance:agriculture', ['craft' => 10, 'science' => 5, 'democracy' => 20]);

        $credits = new AdvanceCreditsCalculator()->creditsFor($agriculture, self::FACETS);

        $this->assertSame(['art' => 0, 'civic' => 0, 'craft' => 10, 'religion' => 0, 'science' => 5], $credits['facets']);
        $this->assertSame(['democracy' => 20], $credits['named']);
    }

    #[Test]
    public function creditsForCumulatesAcrossOwnedAdvances(): void
    {
        $agriculture = $this->entitlementsFor('advance:agriculture', ['craft' => 10, 'science' => 5, 'democracy' => 20]);
        $monarchy = $this->entitlementsFor('advance:monarchy', ['religion' => 5, 'civic' => 10, 'law' => 10]);

        $credits = new AdvanceCreditsCalculator()->creditsFor([...$agriculture, ...$monarchy], self::FACETS);

        $this->assertSame(['art' => 0, 'civic' => 10, 'craft' => 10, 'religion' => 5, 'science' => 5], $credits['facets']);
        $this->assertSame(['democracy' => 20, 'law' => 10], $credits['named']);
    }

    #[Test]
    public function creditsForMergesElectiveEntitlementsIntoTheFacetTotals(): void
    {
        $agriculture = $this->entitlementsFor('advance:agriculture', ['craft' => 10, 'science' => 5, 'democracy' => 20]);
        $elective = $this->entitlementsFor('elective', ['craft' => 10, 'science' => 10]);

        $credits = new AdvanceCreditsCalculator()->creditsFor([...$agriculture, ...$elective], self::FACETS);

        $this->assertSame(['art' => 0, 'civic' => 0, 'craft' => 20, 'religion' => 0, 'science' => 15], $credits['facets']);
    }

    /**
     * Entitlements are read opaquely by scope regardless of origin, matching
     * AdvancePriceResolver's own named-credit rule (see
     * AdvancePriceResolverTest::resolveSumsNamedCreditsFromOwnedAdvancesAndElectiveCreditsTogether()):
     * an elective entitlement sharing a named key sums straight into the
     * same named total, it is never held back because it isn't an owned
     * advance's credit.
     */
    #[Test]
    public function creditsForFoldsAnyEntitlementSharingANamedKeyIntoTheSameNamedTotal(): void
    {
        $agriculture = $this->entitlementsFor('advance:agriculture', ['craft' => 10, 'science' => 5, 'democracy' => 20]);
        $bonus = new Entitlement('democracy', 5, 'elective');

        $credits = new AdvanceCreditsCalculator()->creditsFor([...$agriculture, $bonus], self::FACETS);

        $this->assertSame(['democracy' => 25], $credits['named']);
    }

    /**
     * Same owned pair as AdvancePriceResolverTest::resolveCreditsOnlyTheBestFacet...():
     * pottery grants craft 10, anatomy grants craft 5 and science 20. Pricing
     * engineering through the resolver only ever credits the best facet
     * (science's 20) — craft's 15 never shows up in that net cost. This
     * display aggregate has no single product in mind, so it never collapses
     * facets against each other: craft's 15 stays visible right alongside
     * science's 20.
     */
    #[Test]
    public function creditsForKeepsEachFacetsFullSumWhereTheResolverWouldOnlyCreditTheBest(): void
    {
        $pottery = $this->entitlementsFor('advance:pottery', ['art' => 5, 'craft' => 10, 'agriculture' => 10]);
        $anatomy = $this->entitlementsFor('advance:anatomy', ['craft' => 5, 'science' => 20]);

        $credits = new AdvanceCreditsCalculator()->creditsFor([...$pottery, ...$anatomy], self::FACETS);

        $this->assertSame(['art' => 5, 'civic' => 0, 'craft' => 15, 'religion' => 0, 'science' => 20], $credits['facets']);
        $this->assertSame(['agriculture' => 10], $credits['named']);
    }

    /**
     * @param array<string, int> $credits
     *
     * @return list<Entitlement>
     */
    private function entitlementsFor(string $source, array $credits): array
    {
        $entitlements = [];

        foreach ($credits as $scope => $value) {
            $entitlements[] = new Entitlement($scope, $value, $source);
        }

        return $entitlements;
    }
}
