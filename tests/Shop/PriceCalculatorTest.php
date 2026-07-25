<?php

declare(strict_types=1);

namespace App\Tests\Shop;

use App\Shop\Service\PriceCalculator;
use App\Tests\Shop\Support\FakeProduct;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class PriceCalculatorTest extends TestCase
{
    private const array FACETS = ['art', 'civic', 'craft', 'religion', 'science'];

    #[Test]
    public function netCostWithoutOwnedEqualsRawCost(): void
    {
        $pottery = $this->makeAdvance('pottery', 60, ['craft'], ['art' => 5, 'craft' => 10, 'agriculture' => 10]);

        $this->assertSame(60, new PriceCalculator()->netCost($pottery, []));
    }

    #[Test]
    public function netCostAppliesBestCategoryCredit(): void
    {
        $agriculture = $this->makeAdvance('agriculture', 120, ['craft'], ['craft' => 10, 'science' => 5, 'democracy' => 20]);
        $pottery = $this->makeAdvance('pottery', 60, ['craft'], ['art' => 5, 'craft' => 10, 'agriculture' => 10]);

        $this->assertSame(50, new PriceCalculator()->netCost($pottery, [$agriculture]));
    }

    #[Test]
    public function netCostCombinesNamedAndCategoryCredits(): void
    {
        $agriculture = $this->makeAdvance('agriculture', 120, ['craft'], ['craft' => 10, 'science' => 5, 'democracy' => 20]);
        $democracy = $this->makeAdvance('democracy', 220, ['civic'], ['art' => 5, 'civic' => 20]);

        $this->assertSame(200, new PriceCalculator()->netCost($democracy, [$agriculture]));
    }

    #[Test]
    public function netCostForBiCategoryProductTakesMaxNotSum(): void
    {
        $agriculture = $this->makeAdvance('agriculture', 120, ['craft'], ['craft' => 10, 'science' => 5, 'democracy' => 20]);
        $dramaAndPoetry = $this->makeAdvance('drama_and_poetry', 80, ['art'], ['art' => 10, 'religion' => 5, 'rhetoric' => 10]);
        $mathematics = $this->makeAdvance('mathematics', 250, ['art', 'science'], [
            'art' => 20,
            'civic' => 10,
            'craft' => 10,
            'religion' => 10,
            'science' => 20,
        ]);

        $this->assertSame(240, new PriceCalculator()->netCost($mathematics, [$agriculture, $dramaAndPoetry]));
    }

    #[Test]
    public function netCostIsFlooredAtZero(): void
    {
        $anatomy = $this->makeAdvance('anatomy', 270, ['science'], ['craft' => 5, 'science' => 20]);
        $library = $this->makeAdvance('library', 220, ['science'], ['art' => 5, 'science' => 20]);
        $philosophy = $this->makeAdvance('philosophy', 220, ['religion', 'science'], ['religion' => 20, 'science' => 20]);
        $mathematics = $this->makeAdvance('mathematics', 250, ['art', 'science'], [
            'art' => 20,
            'civic' => 10,
            'craft' => 10,
            'religion' => 10,
            'science' => 20,
        ]);
        $astronavigation = $this->makeAdvance('astronavigation', 80, ['science'], [
            'religion' => 5,
            'science' => 10,
            'calendar' => 10,
        ]);

        $owned = [$anatomy, $library, $philosophy, $mathematics];

        $this->assertSame(0, new PriceCalculator()->netCost($astronavigation, $owned));
    }

    #[Test]
    public function orderTotalDoesNotCreditItemsAgainstEachOther(): void
    {
        $pottery = $this->makeAdvance('pottery', 60, ['craft'], ['art' => 5, 'craft' => 10, 'agriculture' => 10]);
        $agriculture = $this->makeAdvance('agriculture', 120, ['craft'], ['craft' => 10, 'science' => 5, 'democracy' => 20]);

        $total = new PriceCalculator()->orderTotal([$pottery, $agriculture], []);

        $this->assertSame(180, $total);
    }

    #[Test]
    public function orderTotalAppliesOwnedIndependentlyToEachLine(): void
    {
        $agriculture = $this->makeAdvance('agriculture', 120, ['craft'], ['craft' => 10, 'science' => 5, 'democracy' => 20]);
        $pottery = $this->makeAdvance('pottery', 60, ['craft'], ['art' => 5, 'craft' => 10, 'agriculture' => 10]);
        $democracy = $this->makeAdvance('democracy', 220, ['civic'], ['art' => 5, 'civic' => 20]);

        $total = new PriceCalculator()->orderTotal([$pottery, $democracy], [$agriculture]);

        $this->assertSame(250, $total);
    }

    #[Test]
    public function netCostMergesBonusCreditsIntoTheBestOwnedCategory(): void
    {
        $pottery = $this->makeAdvance('pottery', 60, ['craft'], ['art' => 5, 'craft' => 10, 'agriculture' => 10]);

        $this->assertSame(40, new PriceCalculator()->netCost($pottery, [], ['craft' => 20]));
    }

    #[Test]
    public function netCostCombinesOwnedAndBonusCreditsForTheSameCategory(): void
    {
        $agriculture = $this->makeAdvance('agriculture', 120, ['craft'], ['craft' => 10, 'science' => 5, 'democracy' => 20]);
        $pottery = $this->makeAdvance('pottery', 60, ['craft'], ['art' => 5, 'craft' => 10, 'agriculture' => 10]);

        $this->assertSame(30, new PriceCalculator()->netCost($pottery, [$agriculture], ['craft' => 20]));
    }

    #[Test]
    public function creditsForMergesBonusCreditsIntoTheFacetTotals(): void
    {
        $agriculture = $this->makeAdvance('agriculture', 120, ['craft'], ['craft' => 10, 'science' => 5, 'democracy' => 20]);

        $credits = new PriceCalculator()->creditsFor([$agriculture], ['craft' => 10, 'science' => 10], self::FACETS);

        $this->assertSame(['art' => 0, 'civic' => 0, 'craft' => 20, 'religion' => 0, 'science' => 15], $credits['facets']);
    }

    #[Test]
    public function creditsForWithNoOwnedReturnsAllZeroFacetsAndNoNamedCredits(): void
    {
        $credits = new PriceCalculator()->creditsFor([], [], self::FACETS);

        $this->assertSame(['art' => 0, 'civic' => 0, 'craft' => 0, 'religion' => 0, 'science' => 0], $credits['facets']);
        $this->assertSame([], $credits['named']);
    }

    #[Test]
    public function creditsForAggregatesOneOwnedAdvance(): void
    {
        $agriculture = $this->makeAdvance('agriculture', 120, ['craft'], ['craft' => 10, 'science' => 5, 'democracy' => 20]);

        $credits = new PriceCalculator()->creditsFor([$agriculture], [], self::FACETS);

        $this->assertSame(['art' => 0, 'civic' => 0, 'craft' => 10, 'religion' => 0, 'science' => 5], $credits['facets']);
        $this->assertSame(['democracy' => 20], $credits['named']);
    }

    #[Test]
    public function creditsForCumulatesAcrossOwnedAdvances(): void
    {
        $agriculture = $this->makeAdvance('agriculture', 120, ['craft'], ['craft' => 10, 'science' => 5, 'democracy' => 20]);
        $monarchy = $this->makeAdvance('monarchy', 60, ['civic'], ['religion' => 5, 'civic' => 10, 'law' => 10]);

        $credits = new PriceCalculator()->creditsFor([$agriculture, $monarchy], [], self::FACETS);

        $this->assertSame(['art' => 0, 'civic' => 10, 'craft' => 10, 'religion' => 5, 'science' => 5], $credits['facets']);
        $this->assertSame(['democracy' => 20, 'law' => 10], $credits['named']);
    }

    /**
     * @param list<string>       $facets
     * @param array<string, int> $credits
     */
    private function makeAdvance(string $key, int $cost, array $facets, array $credits): FakeProduct
    {
        return new FakeProduct(key: $key, cost: $cost, facets: $facets, credits: $credits);
    }
}
