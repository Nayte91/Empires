<?php

declare(strict_types=1);

namespace Userforged\ShopEngine\Tests;

use Userforged\ShopEngine\Service\PriceCalculator;
use Userforged\ShopEngine\Service\ProductCatalog;
use Userforged\ShopEngine\Tests\Support\FakeBuyer;
use Userforged\ShopEngine\Tests\Support\FakePriceResolver;
use Userforged\ShopEngine\Tests\Support\FakeProduct;
use Userforged\ShopEngine\Tests\Support\FakeProductProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ProductCatalogTest extends TestCase
{
    #[Test]
    public function productsForExcludesWhatTheBuyerAlreadyOwns(): void
    {
        $productCatalog = $this->catalogOf('pottery', 'agriculture', 'democracy');

        $products = $productCatalog->productsFor(new FakeBuyer(['agriculture']), []);

        $this->assertSame(['pottery', 'democracy'], array_column($products, 'key'));
    }

    /**
     * A gap in the returned array would serialize as a JSON object instead of
     * a JSON array — the kind of break a host only discovers in its frontend.
     */
    #[Test]
    public function productsForReindexesAfterRemovingOwnedProducts(): void
    {
        $productCatalog = $this->catalogOf('pottery', 'agriculture', 'democracy');

        $products = $productCatalog->productsFor(new FakeBuyer(['pottery']), []);

        $this->assertSame([0, 1], array_keys($products));
    }

    #[Test]
    public function productsForPricesEveryProductThroughThePriceResolver(): void
    {
        $productCatalog = new ProductCatalog(
            new FakeProductProvider([new FakeProduct(key: 'pottery', cost: 60)]),
            new PriceCalculator(new FakePriceResolver(['pottery' => 50])),
        );

        $products = $productCatalog->productsFor(new FakeBuyer(), []);

        $this->assertSame(50, $products[0]->netCost);
    }

    #[Test]
    public function productsForFlagsTheProductsAlreadyInTheCart(): void
    {
        $productCatalog = $this->catalogOf('pottery', 'agriculture');

        $products = $productCatalog->productsFor(new FakeBuyer(), ['agriculture']);

        $this->assertFalse($products[0]->inCart);
        $this->assertTrue($products[1]->inCart);
    }

    /**
     * ProductProviderInterface makes ordering the provider's responsibility
     * and forbids callers from re-sorting — a host sorting its catalog by
     * cost, or by anything else, must see that order survive.
     */
    #[Test]
    public function productsForPreservesTheProvidersOrdering(): void
    {
        $productCatalog = $this->catalogOf('democracy', 'pottery', 'agriculture');

        $products = $productCatalog->productsFor(new FakeBuyer(), []);

        $this->assertSame(['democracy', 'pottery', 'agriculture'], array_column($products, 'key'));
    }

    #[Test]
    public function productsForWithAnEmptyCatalogReturnsNothing(): void
    {
        $productCatalog = new ProductCatalog(new FakeProductProvider(), new PriceCalculator(new FakePriceResolver()));

        $this->assertSame([], $productCatalog->productsFor(new FakeBuyer(), []));
    }

    private function catalogOf(string ...$keys): ProductCatalog
    {
        return new ProductCatalog(
            new FakeProductProvider(array_map(static fn (string $key): FakeProduct => new FakeProduct(key: $key, cost: 60), $keys)),
            new PriceCalculator(new FakePriceResolver()),
        );
    }
}
