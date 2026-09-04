<?php

declare(strict_types=1);

namespace App\Tests\Unit\Presentation\Shop;

use App\Presentation\Shop\CatalogSort;
use App\Presentation\Shop\CatalogView;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class CatalogViewTest extends TestCase
{
    #[Test]
    #[DataProvider('provideTheKioskViewOrdersByNetPriceWhateverLockAndBudgetItWasGivenCases')]
    public function theKioskViewOrdersByNetPriceWhateverLockAndBudgetItWasGiven(bool $locked, ?int $remainingBudget): void
    {
        $view = CatalogView::kiosk($locked, $remainingBudget);

        $this->assertSame($locked, $view->locked);
        $this->assertSame($remainingBudget, $view->remainingBudget);
        $this->assertSame(CatalogSort::NetPrice, $view->sort);
    }

    public static function provideTheKioskViewOrdersByNetPriceWhateverLockAndBudgetItWasGivenCases(): iterable
    {
        yield 'an open turn with no budget set' => [false, null];

        yield 'an open turn under a budget' => [false, 120];

        yield 'an open turn whose budget is already spent to the penny' => [false, 0];

        yield 'a budget gone negative, kept raw rather than clamped' => [false, -40];

        yield 'a turn whose order has already been validated' => [true, null];
    }

    #[Test]
    public function theKioskViewTakesTheSortTheBuyerChose(): void
    {
        $view = CatalogView::kiosk(false, null, CatalogSort::Name);

        $this->assertSame(CatalogSort::Name, $view->sort);
    }

    #[Test]
    public function thePosViewIsNeverLockedNeverBudgetedAndKeepsTheListPriceOrder(): void
    {
        $view = CatalogView::pos();

        $this->assertFalse($view->locked);
        $this->assertNull($view->remainingBudget);
        $this->assertSame(CatalogSort::ListPrice, $view->sort);
    }
}
