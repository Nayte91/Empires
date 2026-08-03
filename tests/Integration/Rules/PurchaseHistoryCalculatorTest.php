<?php

declare(strict_types=1);

namespace App\Tests\Integration\Rules;

use App\Rules\PurchaseHistoryCalculator;
use App\State\Order;
use App\State\Player;
use App\Tests\Support\Fixture\GameBuilder;
use App\Tests\Support\Fixture\PlayerBuilder;
use App\Tests\Support\GameFixtureTrait;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Userforged\ShopEngine\Dto\OrderLine;
use Userforged\ShopEngine\OrderStatus;

/**
 * The series behind the saga's purchase-value chart, and the home of two rulings that are product
 * decisions rather than arithmetic — nothing else in the codebase guards either:
 *
 * 1. a turn with no order counts as zero, so the average divides by every turn of play from six
 *    onward and answers "how much per turn of play", not "how much per turn bought";
 * 2. a game too short to reach turn six has nothing to average and returns null — which is a
 *    different fact from a player who played twenty turns and bought nothing, whose average is a
 *    genuine zero. The two must never collapse into one another, in either direction.
 *
 * Reading validated orders back off the database is the whole job, so this is a database test
 * rather than a unit one.
 */
final class PurchaseHistoryCalculatorTest extends WebTestCase
{
    use GameFixtureTrait;

    private PurchaseHistoryCalculator $purchaseHistoryCalculator; // @phpstan-ignore property.uninitialized (initialized in setUp)

    protected function setUp(): void
    {
        $this->initEntityManager();

        $this->purchaseHistoryCalculator = self::getContainer()->get(PurchaseHistoryCalculator::class);
    }

    /**
     * Ruling one, at series level. The gaps are the chart's x-axis as much as the purchases are: a
     * series that skipped them would plot turn 4's basket at turn 2's position.
     */
    #[Test]
    public function aTurnWithNoValidatedOrderCountsAsZeroRatherThanBeingSkipped(): void
    {
        $player = $this->createPlayerInAGameOf(5);
        $this->createValidatedOrder($player, 2, 100);
        $this->createValidatedOrder($player, 4, 250);

        $this->assertSame([0, 100, 0, 250, 0], $this->purchaseHistoryCalculator->totalsPerTurn($player));
    }

    /** The x-axis is the game that was played, not the twenty turns the rules allow. */
    #[Test]
    public function theSeriesStopsOnTheTurnTheGameStoppedOn(): void
    {
        $player = $this->createPlayerInAGameOf(3);
        $this->createValidatedOrder($player, 1, 60);

        $this->assertCount(3, $this->purchaseHistoryCalculator->totalsPerTurn($player));
    }

    /**
     * A basket the operator never validated was never delivered, so it never cost anything. The
     * fixture is deliberately impossible — a basket carrying a total while still unvalidated — for
     * the one reason that a discarded total is the only way the filter is observable at all: a
     * realistic pending order has a null total, which the series would floor to the same zero
     * whether it was filtered out or read in.
     */
    #[Test]
    #[DataProvider('provideABasketThatWasNeverValidatedNeverEntersTheSeriesCases')]
    public function aBasketThatWasNeverValidatedNeverEntersTheSeries(OrderStatus $status): void
    {
        $player = $this->createPlayerInAGameOf(3);
        $this->createValidatedOrder($player, 1, 100);
        $this->createOrderCarryingATotalUnder($player, 2, 500, $status);

        $this->assertSame([100, 0, 0], $this->purchaseHistoryCalculator->totalsPerTurn($player));
    }

    public static function provideABasketThatWasNeverValidatedNeverEntersTheSeriesCases(): iterable
    {
        yield 'a basket still awaiting the operator' => [OrderStatus::Pending];

        yield 'a basket the operator turned down' => [OrderStatus::Rejected];
    }

    /** The chart is one player's, and every player at the table shops from the same catalogue on the same turns. */
    #[Test]
    public function aBasketBoughtByAnotherPlayerNeverEntersTheSeries(): void
    {
        $game = GameBuilder::create()->withCurrentTurn(3)->persist($this->entityManager);
        $alice = PlayerBuilder::named('Alice')->in($game)->persist($this->entityManager);
        $bob = PlayerBuilder::named('Bob')->in($game)->withEmpire('hellas')->persist($this->entityManager);
        $this->createValidatedOrder($bob, 2, 400);

        $this->assertSame([0, 0, 0], $this->purchaseHistoryCalculator->totalsPerTurn($alice));
        $this->assertSame([0, 400, 0], $this->purchaseHistoryCalculator->totalsPerTurn($bob));
    }

    /**
     * Ruling one, at average level, and the number that makes it visible: 300 spent over turns 6 to
     * 10 averages 60 per turn of play. Dividing by the two turns a basket happened to be placed
     * would have said 150 — a defensible figure for a different question than the one asked.
     */
    #[Test]
    public function theAverageDividesByEveryTurnFromSixOnwardIncludingTheOnesWithNoOrder(): void
    {
        $player = $this->createPlayerInAGameOf(10);
        $this->createValidatedOrder($player, 6, 100);
        $this->createValidatedOrder($player, 8, 200);

        $this->assertEqualsWithDelta(60.0, $this->purchaseHistoryCalculator->averageFromTurnSix($player), PHP_FLOAT_EPSILON);
    }

    /**
     * The two halves read the same orders and answer different questions: an early basket is part of
     * the history the chart draws, and no part of the rate the average reports. A thousand spent on
     * turn 1 must move the curve and leave the average on turn 6's hundred alone.
     */
    #[Test]
    public function aPurchaseMadeBeforeTurnSixCountsInTheSeriesAndNeverInTheAverage(): void
    {
        $player = $this->createPlayerInAGameOf(6);
        $this->createValidatedOrder($player, 1, 1000);
        $this->createValidatedOrder($player, 6, 100);

        $this->assertSame([1000, 0, 0, 0, 0, 100], $this->purchaseHistoryCalculator->totalsPerTurn($player));
        $this->assertEqualsWithDelta(100.0, $this->purchaseHistoryCalculator->averageFromTurnSix($player), PHP_FLOAT_EPSILON);
    }

    /**
     * Ruling two, and the trap inside it. Nobody buys in these fixtures, so the only thing that
     * separates the rows is how long the game ran: too short, and there is nothing to average, which
     * a zero would misreport as a measurement; long enough, and a player who bought nothing has a
     * true average of zero. Same absent-versus-zero distinction, both sides of the same boundary.
     */
    #[Test]
    #[DataProvider('provideNothingToAverageIsNeverReportedAsAnAverageOfZeroCases')]
    public function nothingToAverageIsNeverReportedAsAnAverageOfZero(int $currentTurn, ?float $expected): void
    {
        $player = $this->createPlayerInAGameOf($currentTurn);

        $this->assertSame($expected, $this->purchaseHistoryCalculator->averageFromTurnSix($player));
    }

    public static function provideNothingToAverageIsNeverReportedAsAnAverageOfZeroCases(): iterable
    {
        yield 'a game of one turn has nothing to average' => [1, null];

        yield 'a game stopping one turn short of the window still has nothing to average' => [5, null];

        yield 'turn six is the first that can be averaged, and buying nothing there is a real zero' => [6, 0.0];

        yield 'twenty turns of buying nothing is an average of zero, not the absence of one' => [20, 0.0];
    }

    private function createPlayerInAGameOf(int $currentTurn): Player
    {
        $game = GameBuilder::create()->withCurrentTurn($currentTurn)->persist($this->entityManager);

        return PlayerBuilder::named('Alice')->in($game)->persist($this->entityManager);
    }

    private function createValidatedOrder(Player $player, int $turn, int $total): void
    {
        $this->createOrderCarryingATotalUnder($player, $turn, $total, OrderStatus::Validated);
    }

    /**
     * freeze() is what puts a total on an order, and it refuses to run twice — so the marking is
     * flipped afterwards for the statuses that would never carry one in production.
     */
    private function createOrderCarryingATotalUnder(Player $player, int $turn, int $total, OrderStatus $status): void
    {
        $order = new Order($player, $turn);
        $order->freeze([new OrderLine('pottery', $total)], $total);
        $order->setMarking($status->value);

        $this->entityManager->persist($order);
        $this->entityManager->flush();
    }
}
