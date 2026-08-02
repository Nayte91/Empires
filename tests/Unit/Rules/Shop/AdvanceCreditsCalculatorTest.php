<?php

declare(strict_types=1);

namespace App\Tests\Unit\Rules\Shop;

use App\Rules\Ruleset\Advance;
use App\Rules\Shop\AdvanceCreditsCalculator;
use App\Rules\Shop\Entitlement;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class AdvanceCreditsCalculatorTest extends TestCase
{
    private const array FACETS = ['art', 'civic', 'craft', 'religion', 'science'];

    #[Test]
    public function creditsForWithNoEntitlementsReturnsAllZeroFacetsAndNoNamedCredits(): void
    {
        $credits = new AdvanceCreditsCalculator()->creditsFor([], self::FACETS);

        $this->assertSame($this->facetCredits([]), $credits['facets']);
        $this->assertSame([], $credits['named']);
    }

    #[Test]
    public function creditsForAggregatesOneOwnedAdvancesEntitlements(): void
    {
        $agriculture = $this->entitlementsFor(['craft' => 10, 'science' => 5, 'democracy' => 20]);

        $credits = new AdvanceCreditsCalculator()->creditsFor($agriculture, self::FACETS);

        $this->assertSame($this->facetCredits(['craft' => 10, 'science' => 5]), $credits['facets']);
        $this->assertSame($this->namedCredits(['democracy' => 20]), $credits['named']);
    }

    #[Test]
    public function creditsForCumulatesAcrossOwnedAdvances(): void
    {
        $agriculture = $this->entitlementsFor(['craft' => 10, 'science' => 5, 'democracy' => 20]);
        $monarchy = $this->entitlementsFor(['religion' => 5, 'civic' => 10, 'law' => 10]);

        $credits = new AdvanceCreditsCalculator()->creditsFor([...$agriculture, ...$monarchy], self::FACETS);

        $this->assertSame($this->facetCredits(['civic' => 10, 'craft' => 10, 'religion' => 5, 'science' => 5]), $credits['facets']);
        $this->assertSame($this->namedCredits(['democracy' => 20, 'law' => 10]), $credits['named']);
    }

    #[Test]
    public function creditsForMergesElectiveEntitlementsIntoTheFacetTotals(): void
    {
        $agriculture = $this->entitlementsFor(['craft' => 10, 'science' => 5, 'democracy' => 20]);
        $elective = $this->entitlementsFor(['craft' => 10, 'science' => 10]);

        $credits = new AdvanceCreditsCalculator()->creditsFor([...$agriculture, ...$elective], self::FACETS);

        $this->assertSame($this->facetCredits(['craft' => 20, 'science' => 15]), $credits['facets']);
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
        $agriculture = $this->entitlementsFor(['craft' => 10, 'science' => 5, 'democracy' => 20]);
        $bonus = new Entitlement('democracy', 5);

        $credits = new AdvanceCreditsCalculator()->creditsFor([...$agriculture, $bonus], self::FACETS);

        $this->assertSame($this->namedCredits(['democracy' => 25]), $credits['named']);
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
        $pottery = $this->entitlementsFor(['art' => 5, 'craft' => 10, 'agriculture' => 10]);
        $anatomy = $this->entitlementsFor(['craft' => 5, 'science' => 20]);

        $credits = new AdvanceCreditsCalculator()->creditsFor([...$pottery, ...$anatomy], self::FACETS);

        $this->assertSame($this->facetCredits(['art' => 5, 'craft' => 15, 'science' => 20]), $credits['facets']);
        $this->assertSame($this->namedCredits(['agriculture' => 10]), $credits['named']);
    }

    #[Test]
    public function creditsForMarksANamedCreditSpentOnceItsOwnAdvanceIsOwned(): void
    {
        $agriculture = $this->entitlementsFor(['craft' => 10, 'democracy' => 20]);

        $credits = new AdvanceCreditsCalculator()->creditsFor($agriculture, self::FACETS, ['democracy'], []);

        $this->assertSame(['amount' => 20, 'spent' => true], $credits['named']['democracy']);
    }

    #[Test]
    #[DataProvider('provideCreditsForMarksAFacetCreditSpentOnlyOnceEveryCarrierIsOwnedCases')]
    public function creditsForMarksAFacetCreditSpentOnlyOnceEveryCarrierIsOwned(int $ownedCarriers, bool $expectedSpent): void
    {
        $catalogue = $this->carriersOf('religion', 12);
        $ownedKeys = \array_slice(array_column($catalogue, 'key'), 0, $ownedCarriers);

        $credits = new AdvanceCreditsCalculator()->creditsFor($this->entitlementsFor(['religion' => 5]), self::FACETS, $ownedKeys, $catalogue);

        $this->assertSame(['amount' => 5, 'spent' => $expectedSpent], $credits['facets']['religion']);
    }

    /**
     * @return iterable<string, array{int, bool}>
     */
    public static function provideCreditsForMarksAFacetCreditSpentOnlyOnceEveryCarrierIsOwnedCases(): iterable
    {
        yield 'eleven of the twelve carriers owned' => [11, false];

        yield 'all twelve carriers owned' => [12, true];
    }

    /**
     * Pins the `[] === $carriers` guard of the private facet check, which no
     * other tier can reach: every real category in config/game/advances.yaml
     * has twelve carriers, so a carrier-less facet only ever arises here.
     * Without the guard, array_all() over an empty carrier list returns true
     * and a facet nobody can ever spend would report itself as spent.
     */
    #[Test]
    public function creditsForLeavesAFacetUnspentWhenNoAdvanceInTheCatalogueCarriesIt(): void
    {
        $catalogue = $this->carriersOf('science', 3);

        $credits = new AdvanceCreditsCalculator()->creditsFor($this->entitlementsFor(['religion' => 5]), self::FACETS, array_column($catalogue, 'key'), $catalogue);

        $this->assertSame(['amount' => 5, 'spent' => false], $credits['facets']['religion']);
    }

    /**
     * The empty-versus-spent pair pinned by issue #24: a facet worth 0 whose
     * carriers are all owned is genuinely both. The calculator reports the two
     * facts as it finds them — amount 0 AND spent — because deciding between
     * them is not its job: templates/molecules/discounts.html.twig tests
     * `amount == 0` first, so "empty" wins and the row is dimmed without a
     * strikethrough. Striking it would claim "you had this and it is gone",
     * which is untrue of a credit never earned. Hence raw data saying spent
     * while the screen shows none.
     */
    #[Test]
    public function creditsForStillReportsAFacetWorthNothingAsSpentWhenEveryCarrierIsOwned(): void
    {
        $catalogue = $this->carriersOf('religion', 12);

        $credits = new AdvanceCreditsCalculator()->creditsFor([], self::FACETS, array_column($catalogue, 'key'), $catalogue);

        $this->assertSame(['amount' => 0, 'spent' => true], $credits['facets']['religion']);
    }

    /**
     * @param array<string, int> $credits
     *
     * @return list<Entitlement>
     */
    private function entitlementsFor(array $credits): array
    {
        $entitlements = [];

        foreach ($credits as $scope => $value) {
            $entitlements[] = new Entitlement($scope, $value);
        }

        return $entitlements;
    }

    /**
     * Builds the expected facets map: every known facet at zero, overridden by whichever
     * amounts the test cares about. No test consuming this helper passes owned keys or a
     * catalogue, so every facet it describes is unspent.
     *
     * @param array<string, int> $nonZeroAmounts
     *
     * @return array<string, array{amount: int, spent: bool}>
     */
    private function facetCredits(array $nonZeroAmounts): array
    {
        $credits = [];

        foreach (self::FACETS as $facet) {
            $credits[$facet] = ['amount' => $nonZeroAmounts[$facet] ?? 0, 'spent' => false];
        }

        return $credits;
    }

    /**
     * @param array<string, int> $amounts
     *
     * @return array<string, array{amount: int, spent: bool}>
     */
    private function namedCredits(array $amounts): array
    {
        return array_map(static fn (int $amount): array => ['amount' => $amount, 'spent' => false], $amounts);
    }

    /**
     * @return list<Advance>
     */
    private function carriersOf(string $facet, int $count): array
    {
        $advances = [];

        for ($i = 1; $i <= $count; ++$i) {
            $advances[] = $this->makeAdvance($facet.'_'.$i, [$facet]);
        }

        return $advances;
    }

    /**
     * @param list<string> $facets
     */
    private function makeAdvance(string $key, array $facets): Advance
    {
        return new Advance(
            key: $key,
            name: str_replace('_', ' ', $key),
            fileName: $key.'.webp',
            cost: 0,
            points: 0,
            facets: $facets,
            credits: [],
            mitigations: [],
            aggravations: [],
        );
    }
}
