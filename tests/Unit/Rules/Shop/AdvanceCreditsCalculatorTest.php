<?php

declare(strict_types=1);

namespace App\Tests\Unit\Rules\Shop;

use App\Rules\Ruleset\Advance;
use App\Rules\Shop\AdvanceCreditsCalculator;
use App\Rules\Shop\Entitlement;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use App\Tests\Support\GameConfig;

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

    #[Test]
    public function creditsForFoldsAnyEntitlementSharingANamedKeyIntoTheSameNamedTotal(): void
    {
        $agriculture = $this->entitlementsFor(['craft' => 10, 'science' => 5, 'democracy' => 20]);
        $bonus = new Entitlement('democracy', 5);

        $credits = new AdvanceCreditsCalculator()->creditsFor([...$agriculture, $bonus], self::FACETS);

        $this->assertSame($this->namedCredits(['democracy' => 25]), $credits['named']);
    }

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
     * Pins the empty-carriers guard: array_all() over an empty list returns true, so a facet nobody
     * can spend would report itself spent. Every real category has twelve carriers, so only this
     * fixture reaches it.
     */
    #[Test]
    public function creditsForLeavesAFacetUnspentWhenNoAdvanceInTheCatalogueCarriesIt(): void
    {
        $catalogue = $this->carriersOf('science', 3);

        $credits = new AdvanceCreditsCalculator()->creditsFor($this->entitlementsFor(['religion' => 5]), self::FACETS, array_column($catalogue, 'key'), $catalogue);

        $this->assertSame(['amount' => 5, 'spent' => false], $credits['facets']['religion']);
    }

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
     * Callers pass no owned keys and no catalogue, so every facet described here is unspent.
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
            $advances[] = GameConfig::advance($facet.'_'.$i, facets: [$facet]);
        }

        return $advances;
    }
}
