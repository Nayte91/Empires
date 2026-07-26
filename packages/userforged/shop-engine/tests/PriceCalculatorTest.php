<?php

declare(strict_types=1);

namespace Userforged\ShopEngine\Tests;

use Userforged\ShopEngine\Service\CreditPriceResolver;
use Userforged\ShopEngine\Service\PriceCalculator;
use Userforged\ShopEngine\Tests\Support\FakeBuyer;
use Userforged\ShopEngine\Tests\Support\FakeProduct;
use Userforged\ShopEngine\Tests\Support\FakeProductProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class PriceCalculatorTest extends TestCase
{
    private const array FACETS = ['art', 'civic', 'craft', 'religion', 'science'];

    #[Test]
    public function orderTotalDoesNotCreditItemsAgainstEachOther(): void
    {
        $pottery = $this->makeAdvance('pottery', 60, ['craft'], ['art' => 5, 'craft' => 10, 'agriculture' => 10]);
        $agriculture = $this->makeAdvance('agriculture', 120, ['craft'], ['craft' => 10, 'science' => 5, 'democracy' => 20]);

        $priceCalculator = new PriceCalculator(new CreditPriceResolver(new FakeProductProvider([$pottery, $agriculture])));

        $total = $priceCalculator->orderTotal([$pottery, $agriculture], new FakeBuyer());

        $this->assertSame(180, $total);
    }

    #[Test]
    public function orderTotalAppliesOwnedIndependentlyToEachLine(): void
    {
        $agriculture = $this->makeAdvance('agriculture', 120, ['craft'], ['craft' => 10, 'science' => 5, 'democracy' => 20]);
        $pottery = $this->makeAdvance('pottery', 60, ['craft'], ['art' => 5, 'craft' => 10, 'agriculture' => 10]);
        $democracy = $this->makeAdvance('democracy', 220, ['civic'], ['art' => 5, 'civic' => 20]);

        $priceCalculator = new PriceCalculator(new CreditPriceResolver(new FakeProductProvider([$agriculture, $pottery, $democracy])));
        $buyer = new FakeBuyer(ownedKeys: ['agriculture']);

        $total = $priceCalculator->orderTotal([$pottery, $democracy], $buyer);

        $this->assertSame(250, $total);
    }

    #[Test]
    public function creditsForMergesBonusCreditsIntoTheFacetTotals(): void
    {
        $agriculture = $this->makeAdvance('agriculture', 120, ['craft'], ['craft' => 10, 'science' => 5, 'democracy' => 20]);

        $credits = new PriceCalculator(new CreditPriceResolver(new FakeProductProvider()))->creditsFor([$agriculture], ['craft' => 10, 'science' => 10], self::FACETS);

        $this->assertSame(['art' => 0, 'civic' => 0, 'craft' => 20, 'religion' => 0, 'science' => 15], $credits['facets']);
    }

    #[Test]
    public function creditsForWithNoOwnedReturnsAllZeroFacetsAndNoNamedCredits(): void
    {
        $credits = new PriceCalculator(new CreditPriceResolver(new FakeProductProvider()))->creditsFor([], [], self::FACETS);

        $this->assertSame(['art' => 0, 'civic' => 0, 'craft' => 0, 'religion' => 0, 'science' => 0], $credits['facets']);
        $this->assertSame([], $credits['named']);
    }

    #[Test]
    public function creditsForAggregatesOneOwnedAdvance(): void
    {
        $agriculture = $this->makeAdvance('agriculture', 120, ['craft'], ['craft' => 10, 'science' => 5, 'democracy' => 20]);

        $credits = new PriceCalculator(new CreditPriceResolver(new FakeProductProvider()))->creditsFor([$agriculture], [], self::FACETS);

        $this->assertSame(['art' => 0, 'civic' => 0, 'craft' => 10, 'religion' => 0, 'science' => 5], $credits['facets']);
        $this->assertSame(['democracy' => 20], $credits['named']);
    }

    #[Test]
    public function creditsForCumulatesAcrossOwnedAdvances(): void
    {
        $agriculture = $this->makeAdvance('agriculture', 120, ['craft'], ['craft' => 10, 'science' => 5, 'democracy' => 20]);
        $monarchy = $this->makeAdvance('monarchy', 60, ['civic'], ['religion' => 5, 'civic' => 10, 'law' => 10]);

        $credits = new PriceCalculator(new CreditPriceResolver(new FakeProductProvider()))->creditsFor([$agriculture, $monarchy], [], self::FACETS);

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
