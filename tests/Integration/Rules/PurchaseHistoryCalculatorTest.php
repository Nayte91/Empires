<?php

declare(strict_types=1);

namespace App\Tests\Integration\Rules;

use App\Rules\PurchaseHistoryCalculator;
use App\Tests\Support\Fixture\GameBuilder;
use App\Tests\Support\Fixture\PlayerBuilder;
use App\Tests\Support\GameFixtureTrait;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Userforged\ShopEngine\Dto\OrderLine;
use App\Tests\Support\Fixture\OrderBuilder;

final class PurchaseHistoryCalculatorTest extends WebTestCase
{
    use GameFixtureTrait;

    private PurchaseHistoryCalculator $purchaseHistoryCalculator; // @phpstan-ignore property.uninitialized (initialized in setUp)

    protected function setUp(): void
    {
        $this->initEntityManager();

        $this->purchaseHistoryCalculator = self::getContainer()->get(PurchaseHistoryCalculator::class);
    }

    #[Test]
    public function aTurnWithNoValidatedOrderCountsAsZeroRatherThanBeingSkipped(): void
    {
        $player = PlayerBuilder::named('Alice')->in(GameBuilder::create()->withCurrentTurn(5)->build())->persist($this->entityManager);
        OrderBuilder::for($player)->onTurn(2)->withLine(new OrderLine('pottery', 100))->validated(100)->persist($this->entityManager);
        OrderBuilder::for($player)->onTurn(4)->withLine(new OrderLine('pottery', 250))->validated(250)->persist($this->entityManager);

        $this->assertSame([0, 100, 0, 250, 0], $this->purchaseHistoryCalculator->totalsPerTurn($player));
    }

    #[Test]
    public function theSeriesStopsOnTheTurnTheGameStoppedOn(): void
    {
        $player = PlayerBuilder::named('Alice')->in(GameBuilder::create()->withCurrentTurn(3)->build())->persist($this->entityManager);
        OrderBuilder::for($player)->onTurn(1)->withLine(new OrderLine('pottery', 60))->validated(60)->persist($this->entityManager);

        $this->assertCount(3, $this->purchaseHistoryCalculator->totalsPerTurn($player));
    }

    /**
     * The fixture is deliberately impossible — an unvalidated basket carrying a total — because a
     * discarded total is the only way the filter is observable: a realistic pending order floors to
     * the same zero either way.
     */
    #[Test]
    public function aBasketThatWasNeverValidatedNeverEntersTheSeries(): void
    {
        $player = PlayerBuilder::named('Alice')->in(GameBuilder::create()->withCurrentTurn(3)->build())->persist($this->entityManager);
        OrderBuilder::for($player)->onTurn(1)->withLine(new OrderLine('pottery', 100))->validated(100)->persist($this->entityManager);
        OrderBuilder::for($player)->onTurn(2)->withLine(new OrderLine('pottery', 500))->frozenAsPending(500)->persist($this->entityManager);

        $this->assertSame([100, 0, 0], $this->purchaseHistoryCalculator->totalsPerTurn($player));
    }

    #[Test]
    public function aBasketBoughtByAnotherPlayerNeverEntersTheSeries(): void
    {
        $game = GameBuilder::create()->withCurrentTurn(3)->persist($this->entityManager);
        $alice = PlayerBuilder::named('Alice')->in($game)->persist($this->entityManager);
        $bob = PlayerBuilder::named('Bob')->in($game)->withEmpire('hellas')->persist($this->entityManager);
        OrderBuilder::for($bob)->onTurn(2)->withLine(new OrderLine('pottery', 400))->validated(400)->persist($this->entityManager);

        $this->assertSame([0, 0, 0], $this->purchaseHistoryCalculator->totalsPerTurn($alice));
        $this->assertSame([0, 400, 0], $this->purchaseHistoryCalculator->totalsPerTurn($bob));
    }

    #[Test]
    public function theAverageDividesByEveryTurnFromSixOnwardIncludingTheOnesWithNoOrder(): void
    {
        $player = PlayerBuilder::named('Alice')->in(GameBuilder::create()->withCurrentTurn(10)->build())->persist($this->entityManager);
        OrderBuilder::for($player)->onTurn(6)->withLine(new OrderLine('pottery', 100))->validated(100)->persist($this->entityManager);
        OrderBuilder::for($player)->onTurn(8)->withLine(new OrderLine('pottery', 200))->validated(200)->persist($this->entityManager);

        $this->assertEqualsWithDelta(60.0, $this->purchaseHistoryCalculator->averageFromTurnSix($player), PHP_FLOAT_EPSILON);
    }

    #[Test]
    public function aPurchaseMadeBeforeTurnSixCountsInTheSeriesAndNeverInTheAverage(): void
    {
        $player = PlayerBuilder::named('Alice')->in(GameBuilder::create()->withCurrentTurn(6)->build())->persist($this->entityManager);
        OrderBuilder::for($player)->onTurn(1)->withLine(new OrderLine('pottery', 1000))->validated(1000)->persist($this->entityManager);
        OrderBuilder::for($player)->onTurn(6)->withLine(new OrderLine('pottery', 100))->validated(100)->persist($this->entityManager);

        $this->assertSame([1000, 0, 0, 0, 0, 100], $this->purchaseHistoryCalculator->totalsPerTurn($player));
        $this->assertEqualsWithDelta(100.0, $this->purchaseHistoryCalculator->averageFromTurnSix($player), PHP_FLOAT_EPSILON);
    }

    #[Test]
    #[DataProvider('provideNothingToAverageIsNeverReportedAsAnAverageOfZeroCases')]
    public function nothingToAverageIsNeverReportedAsAnAverageOfZero(int $currentTurn, ?float $expected): void
    {
        $player = PlayerBuilder::named('Alice')->in(GameBuilder::create()->withCurrentTurn($currentTurn)->build())->persist($this->entityManager);

        $this->assertSame($expected, $this->purchaseHistoryCalculator->averageFromTurnSix($player));
    }

    public static function provideNothingToAverageIsNeverReportedAsAnAverageOfZeroCases(): iterable
    {
        yield 'a game of one turn has nothing to average' => [1, null];

        yield 'a game stopping one turn short of the window still has nothing to average' => [5, null];

        yield 'turn six is the first that can be averaged, and buying nothing there is a real zero' => [6, 0.0];

        yield 'twenty turns of buying nothing is an average of zero, not the absence of one' => [20, 0.0];
    }
}
