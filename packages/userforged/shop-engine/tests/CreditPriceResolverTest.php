<?php

declare(strict_types=1);

namespace Userforged\ShopEngine\Tests;

use Userforged\ShopEngine\Service\CreditPriceResolver;
use Userforged\ShopEngine\Tests\Support\FakeBuyer;
use Userforged\ShopEngine\Tests\Support\FakeProduct;
use Userforged\ShopEngine\Tests\Support\FakeProductProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class CreditPriceResolverTest extends TestCase
{
    #[Test]
    public function resolveWithoutOwnedEqualsRawCost(): void
    {
        $pottery = $this->makeAdvance('pottery', 60, ['craft'], ['art' => 5, 'craft' => 10, 'agriculture' => 10]);

        $resolver = new CreditPriceResolver(new FakeProductProvider([$pottery]));

        $this->assertSame(60, $resolver->resolve($pottery, new FakeBuyer()));
    }

    #[Test]
    public function resolveAppliesBestFacetCredit(): void
    {
        $agriculture = $this->makeAdvance('agriculture', 120, ['craft'], ['craft' => 10, 'science' => 5, 'democracy' => 20]);
        $pottery = $this->makeAdvance('pottery', 60, ['craft'], ['art' => 5, 'craft' => 10, 'agriculture' => 10]);

        $resolver = new CreditPriceResolver(new FakeProductProvider([$agriculture, $pottery]));
        $buyer = new FakeBuyer(ownedKeys: ['agriculture']);

        $this->assertSame(50, $resolver->resolve($pottery, $buyer));
    }

    #[Test]
    public function resolveCombinesNamedAndFacetCredits(): void
    {
        $agriculture = $this->makeAdvance('agriculture', 120, ['craft'], ['craft' => 10, 'science' => 5, 'democracy' => 20]);
        $democracy = $this->makeAdvance('democracy', 220, ['civic'], ['art' => 5, 'civic' => 20]);

        $resolver = new CreditPriceResolver(new FakeProductProvider([$agriculture, $democracy]));
        $buyer = new FakeBuyer(ownedKeys: ['agriculture']);

        $this->assertSame(200, $resolver->resolve($democracy, $buyer));
    }

    #[Test]
    public function resolveForBiFacetProductTakesMaxNotSum(): void
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

        $resolver = new CreditPriceResolver(new FakeProductProvider([$agriculture, $dramaAndPoetry, $mathematics]));
        $buyer = new FakeBuyer(ownedKeys: ['agriculture', 'drama_and_poetry']);

        $this->assertSame(240, $resolver->resolve($mathematics, $buyer));
    }

    #[Test]
    public function resolveIsFlooredAtZero(): void
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
        $resolver = new CreditPriceResolver(new FakeProductProvider([...$owned, $astronavigation]));
        $buyer = new FakeBuyer(ownedKeys: ['anatomy', 'library', 'philosophy', 'mathematics']);

        $this->assertSame(0, $resolver->resolve($astronavigation, $buyer));
    }

    #[Test]
    public function resolveMergesElectiveCreditsIntoTheBestOwnedFacet(): void
    {
        $pottery = $this->makeAdvance('pottery', 60, ['craft'], ['art' => 5, 'craft' => 10, 'agriculture' => 10]);

        $resolver = new CreditPriceResolver(new FakeProductProvider([$pottery]));
        $buyer = new FakeBuyer(electiveCredits: ['craft' => 20]);

        $this->assertSame(40, $resolver->resolve($pottery, $buyer));
    }

    #[Test]
    public function resolveCombinesOwnedAndElectiveCreditsForTheSameFacet(): void
    {
        $agriculture = $this->makeAdvance('agriculture', 120, ['craft'], ['craft' => 10, 'science' => 5, 'democracy' => 20]);
        $pottery = $this->makeAdvance('pottery', 60, ['craft'], ['art' => 5, 'craft' => 10, 'agriculture' => 10]);

        $resolver = new CreditPriceResolver(new FakeProductProvider([$agriculture, $pottery]));
        $buyer = new FakeBuyer(ownedKeys: ['agriculture'], electiveCredits: ['craft' => 20]);

        $this->assertSame(30, $resolver->resolve($pottery, $buyer));
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
