<?php

declare(strict_types=1);

namespace App\Tests\Unit\Presentation\Shop;

use App\Presentation\Shop\CatalogSort;
use App\Presentation\Shop\CatalogView;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * App\Presentation\Shop\CatalogView exists to make two configurations the only two expressible,
 * after three independent flags on App\Presentation\Component\Catalog had produced exactly two
 * combinations anyone ever used. What is worth pinning here is therefore not that the fields are
 * carried — the two screens assert that where it shows — but the part each named constructor
 * decides on its caller's behalf (the order), and that no third configuration is reachable at all.
 */
final class CatalogViewTest extends TestCase
{
    /**
     * The lock and the budget come from the host and pass through untouched, negative remainders
     * included; the order is the kiosk's own decision, the same whatever it was handed.
     */
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

    /** The operator rings up at the printed price on a shelf no turn lock and no budget touches. */
    #[Test]
    public function thePosViewIsNeverLockedNeverBudgetedAndKeepsTheListPriceOrder(): void
    {
        $view = CatalogView::pos();

        $this->assertFalse($view->locked);
        $this->assertNull($view->remainingBudget);
        $this->assertSame(CatalogSort::ListPrice, $view->sort);
    }

    /**
     * The point of the class rather than a detail of it: the two above are exhaustive only for as
     * long as the constructor stays shut. A public one would let a caller assemble the combinations
     * the refactor exists to rule out — a locked POS, a budgeted one, a kiosk sorted by list price —
     * and no screen-level test would notice until one of them shipped.
     */
    #[Test]
    public function noThirdConfigurationIsReachableBecauseTheConstructorIsClosed(): void
    {
        $this->assertTrue(new ReflectionMethod(CatalogView::class, '__construct')->isPrivate());
    }
}
