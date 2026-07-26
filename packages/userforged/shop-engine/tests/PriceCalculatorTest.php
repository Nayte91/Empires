<?php

declare(strict_types=1);

namespace Userforged\ShopEngine\Tests;

use Userforged\ShopEngine\Service\PriceCalculator;
use Userforged\ShopEngine\Tests\Support\FakeBuyer;
use Userforged\ShopEngine\Tests\Support\FakePriceResolver;
use Userforged\ShopEngine\Tests\Support\FakeProduct;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class PriceCalculatorTest extends TestCase
{
    #[Test]
    public function orderTotalSumsTheResolvedNetCostOfEveryLine(): void
    {
        $pottery = new FakeProduct(key: 'pottery', cost: 60);
        $agriculture = new FakeProduct(key: 'agriculture', cost: 120);

        $priceCalculator = new PriceCalculator(new FakePriceResolver(['pottery' => 50, 'agriculture' => 100]));

        $total = $priceCalculator->orderTotal([$pottery, $agriculture], new FakeBuyer());

        $this->assertSame(150, $total);
    }

    #[Test]
    public function orderTotalFloorsEachResolvedLineAtZeroBeforeSumming(): void
    {
        $pottery = new FakeProduct(key: 'pottery', cost: 60);
        $democracy = new FakeProduct(key: 'democracy', cost: 220);

        $priceCalculator = new PriceCalculator(new FakePriceResolver(['pottery' => -10, 'democracy' => 200]));

        $total = $priceCalculator->orderTotal([$pottery, $democracy], new FakeBuyer());

        $this->assertSame(200, $total);
    }
}
