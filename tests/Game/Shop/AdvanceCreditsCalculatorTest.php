<?php

declare(strict_types=1);

namespace App\Tests\Game\Shop;

use App\Game\Dto\Advance;
use App\Game\Shop\AdvanceCreditsCalculator;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class AdvanceCreditsCalculatorTest extends TestCase
{
    private const array FACETS = ['art', 'civic', 'craft', 'religion', 'science'];

    #[Test]
    public function creditsForWithNoOwnedReturnsAllZeroFacetsAndNoNamedCredits(): void
    {
        $credits = new AdvanceCreditsCalculator()->creditsFor([], [], self::FACETS);

        $this->assertSame(['art' => 0, 'civic' => 0, 'craft' => 0, 'religion' => 0, 'science' => 0], $credits['facets']);
        $this->assertSame([], $credits['named']);
    }

    #[Test]
    public function creditsForAggregatesOneOwnedAdvance(): void
    {
        $agriculture = $this->advanceWithCredits('agriculture', ['craft'], ['craft' => 10, 'science' => 5, 'democracy' => 20]);

        $credits = new AdvanceCreditsCalculator()->creditsFor([$agriculture], [], self::FACETS);

        $this->assertSame(['art' => 0, 'civic' => 0, 'craft' => 10, 'religion' => 0, 'science' => 5], $credits['facets']);
        $this->assertSame(['democracy' => 20], $credits['named']);
    }

    #[Test]
    public function creditsForCumulatesAcrossOwnedAdvances(): void
    {
        $agriculture = $this->advanceWithCredits('agriculture', ['craft'], ['craft' => 10, 'science' => 5, 'democracy' => 20]);
        $monarchy = $this->advanceWithCredits('monarchy', ['civic'], ['religion' => 5, 'civic' => 10, 'law' => 10]);

        $credits = new AdvanceCreditsCalculator()->creditsFor([$agriculture, $monarchy], [], self::FACETS);

        $this->assertSame(['art' => 0, 'civic' => 10, 'craft' => 10, 'religion' => 5, 'science' => 5], $credits['facets']);
        $this->assertSame(['democracy' => 20, 'law' => 10], $credits['named']);
    }

    #[Test]
    public function creditsForMergesBonusCreditsIntoTheFacetTotals(): void
    {
        $agriculture = $this->advanceWithCredits('agriculture', ['craft'], ['craft' => 10, 'science' => 5, 'democracy' => 20]);

        $credits = new AdvanceCreditsCalculator()->creditsFor([$agriculture], ['craft' => 10, 'science' => 10], self::FACETS);

        $this->assertSame(['art' => 0, 'civic' => 0, 'craft' => 20, 'religion' => 0, 'science' => 15], $credits['facets']);
    }

    /**
     * Bonus (elective) credits are always facet-keyed — Option promotions
     * split a budget across the same facets ShopConnector::facets() exposes,
     * never against a named product key. creditsFor() only ever folds
     * bonusCredits into the facet totals (see sumCreditsFor() calls above);
     * a bonus sharing a key with a named credit must not inflate it.
     */
    #[Test]
    public function creditsForNamedTotalsIgnoreBonusCreditsEvenWhenTheyShareAKeyWithAnOwnedAdvance(): void
    {
        $agriculture = $this->advanceWithCredits('agriculture', ['craft'], ['craft' => 10, 'science' => 5, 'democracy' => 20]);

        $credits = new AdvanceCreditsCalculator()->creditsFor([$agriculture], ['democracy' => 50], self::FACETS);

        $this->assertSame(['democracy' => 20], $credits['named']);
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
        $pottery = $this->advanceWithCredits('pottery', ['craft'], ['art' => 5, 'craft' => 10, 'agriculture' => 10]);
        $anatomy = $this->advanceWithCredits('anatomy', ['science'], ['craft' => 5, 'science' => 20]);

        $credits = new AdvanceCreditsCalculator()->creditsFor([$pottery, $anatomy], [], self::FACETS);

        $this->assertSame(['art' => 5, 'civic' => 0, 'craft' => 15, 'religion' => 0, 'science' => 20], $credits['facets']);
        $this->assertSame(['agriculture' => 10], $credits['named']);
    }

    /**
     * @param list<string>       $facets
     * @param array<string, int> $credits
     */
    private function advanceWithCredits(string $key, array $facets, array $credits): Advance
    {
        return new Advance(
            key: $key,
            name: str_replace('_', ' ', $key),
            fileName: '',
            cost: 0,
            points: 0,
            facets: $facets,
            credits: $credits,
            mitigations: [],
            aggravations: [],
        );
    }
}
