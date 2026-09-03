<?php

declare(strict_types=1);

namespace App\Tests\Unit\Presentation\Shop;

use App\Presentation\Shop\CatalogSort;
use App\Rules\Ruleset\Advance;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Userforged\ShopEngine\Dto\Product;

final class CatalogSortTest extends TestCase
{
    #[Test]
    #[DataProvider('provideEachSortRanksTheSamePairByItsOwnKeyCases')]
    public function eachSortRanksTheSamePairByItsOwnKey(CatalogSort $sort, int $expectedSign): void
    {
        $agricultureListedAt120PaidAt100 = $this->row('agriculture', 120, 100);
        $potteryListedAt60PaidAt110 = $this->row('pottery', 60, 110);

        $comparison = $sort->compare($agricultureListedAt120PaidAt100, $potteryListedAt60PaidAt110);

        $this->assertSame($expectedSign, $comparison <=> 0);
    }

    public static function provideEachSortRanksTheSamePairByItsOwnKeyCases(): iterable
    {
        yield 'by name, agriculture comes first although it lists at twice the price' => [CatalogSort::Name, -1];

        yield 'by list price, pottery comes first' => [CatalogSort::ListPrice, 1];

        yield 'by net price, agriculture comes first because the discount undercuts pottery' => [CatalogSort::NetPrice, -1];
    }

    #[Test]
    public function sortingByNameTreatsTwoRowsOfTheSameNameAsEqual(): void
    {
        $comparison = CatalogSort::Name->compare($this->row('pottery', 60, 60), $this->row('pottery', 60, 40));

        $this->assertSame(0, $comparison);
    }

    #[Test]
    #[DataProvider('provideEachSortCarriesItsOwnCaptionCases')]
    public function eachSortCarriesItsOwnCaption(CatalogSort $sort, string $expectedLabel): void
    {
        $this->assertSame($expectedLabel, $sort->label());
    }

    public static function provideEachSortCarriesItsOwnCaptionCases(): iterable
    {
        yield 'net price keeps its own name' => [CatalogSort::NetPrice, 'Net price'];

        yield 'name reads as name' => [CatalogSort::Name, 'Name'];

        yield 'list price reads as raw price' => [CatalogSort::ListPrice, 'Raw price'];
    }

    #[Test]
    public function theSortsAreOfferedAsNetPriceThenNameThenRawPrice(): void
    {
        $this->assertSame([CatalogSort::NetPrice, CatalogSort::Name, CatalogSort::ListPrice], CatalogSort::cases());
    }

    /** @return array{advance: Advance, product: Product} */
    private function row(string $name, int $listPrice, int $netCost): array
    {
        $key = str_replace(' ', '_', $name);

        return [
            'advance' => new Advance($key, $name, $key.'.png', $listPrice, 1, ['craft'], [], [], []),
            'product' => new Product($key, $netCost, false, false),
        ];
    }
}
