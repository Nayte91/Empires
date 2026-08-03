<?php

declare(strict_types=1);

namespace App\Tests\Component;

use App\Presentation\Component\PurchaseValue;
use App\Rules\PurchaseHistoryCalculator;
use App\State\Order;
use App\State\Player;
use App\Tests\Support\Fixture\GameBuilder;
use App\Tests\Support\Fixture\PlayerBuilder;
use App\Tests\Support\GameFixtureTrait;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\UX\TwigComponent\Test\InteractsWithTwigComponents;
use Userforged\ShopEngine\Dto\OrderLine;
use Userforged\ShopEngine\OrderStatus;

/**
 * The saga's one chart. What it owns beyond PurchaseHistoryCalculatorTest's arithmetic is how the
 * two answers that calculator can give are told apart on screen: an average of zero and no average
 * at all are different facts, and the only thing separating them in the markup is one attribute.
 * Assert the wrong one and both states read alike to a reader and to this suite.
 */
final class PurchaseValueTest extends WebTestCase
{
    use GameFixtureTrait;
    use InteractsWithTwigComponents;

    /** Inherited from #44 verbatim: any chart following Evolution's pattern collapses on a narrow screen without it. */
    #[Test]
    public function theCanvasTakesItsHeightFromItsContainerRatherThanFromAFixedRatio(): void
    {
        $player = $this->createPlayerInAGameOf(6);

        $options = $this->mountPurchaseValue($player)->getChart()->getOptions();

        $this->assertFalse($options['maintainAspectRatio']);
    }

    /** One line, one point per turn, gaps included — the chart draws the calculator's series and adds nothing to it. */
    #[Test]
    public function theSeriesPlottedIsOneTotalPerTurnOfTheGame(): void
    {
        $player = $this->createPlayerInAGameOf(4);
        $this->createValidatedOrder($player, 2, 100);

        $data = $this->mountPurchaseValue($player)->getChart()->getData();

        $this->assertSame([1, 2, 3, 4], $data['labels']);
        $this->assertCount(1, $data['datasets']);
        $this->assertSame([0, 100, 0, 0], $data['datasets'][0]['data']);
    }

    /**
     * Half of the distinction, and the reason the average is nullable at all: five turns give the
     * window from turn six nothing to divide, and printing a zero there would report a measurement
     * that was never taken.
     */
    #[Test]
    public function aGameTooShortToAverageSaysSoRatherThanShowingAZero(): void
    {
        $player = $this->createPlayerInAGameOf(PurchaseHistoryCalculator::AVERAGE_FROM_TURN - 1);

        $crawler = $this->renderTwigComponent('PurchaseValue', ['player' => $player])->crawler();

        $this->assertNull($this->mountPurchaseValue($player)->getAverage());
        $this->assertCount(1, $crawler->filter('#purchase-value p[data-empty]'));
        $this->assertStringEndsWith('not enough turns played', $crawler->filter('#purchase-value p')->text());
    }

    /**
     * The other half, and the trap: a player who played twenty turns and bought nothing has a real
     * average of zero. It must render as the number it is, with no empty-state marker — collapsing
     * it into "not enough turns played" would deny a measurement that was taken.
     */
    #[Test]
    public function aPlayerWhoBoughtNothingInALongGameShowsAGenuineAverageOfZero(): void
    {
        $player = $this->createPlayerInAGameOf(20);

        $crawler = $this->renderTwigComponent('PurchaseValue', ['player' => $player])->crawler();
        $average = $this->mountPurchaseValue($player)->getAverage();

        // assertNotNull carries the distinction on its own: rector rewrites a float assertSame into
        // assertEqualsWithDelta, and that comparison accepts null as equal to 0.0 — which is exactly
        // the collapse this test exists to forbid.
        $this->assertNotNull($average);
        $this->assertEqualsWithDelta(0.0, $average, PHP_FLOAT_EPSILON);
        $this->assertCount(1, $crawler->filter('#purchase-value p'));
        $this->assertCount(0, $crawler->filter('#purchase-value p[data-empty]'));
        $this->assertStringEndsWith(': 0', $crawler->filter('#purchase-value p')->text());
    }

    /** 300 over the five turns from six to ten, so the figure on screen is a rate and not a sum. */
    #[Test]
    public function theAverageOnScreenIsTheSpendPerTurnOfPlay(): void
    {
        $player = $this->createPlayerInAGameOf(10);
        $this->createValidatedOrder($player, 6, 100);
        $this->createValidatedOrder($player, 8, 200);

        $crawler = $this->renderTwigComponent('PurchaseValue', ['player' => $player])->crawler();

        $this->assertCount(0, $crawler->filter('#purchase-value p[data-empty]'));
        $this->assertStringEndsWith(': 60', $crawler->filter('#purchase-value p')->text());
    }

    private function mountPurchaseValue(Player $player): PurchaseValue
    {
        $component = $this->mountTwigComponent('PurchaseValue', ['player' => $player]);
        $this->assertInstanceOf(PurchaseValue::class, $component);

        return $component;
    }

    private function createPlayerInAGameOf(int $currentTurn): Player
    {
        $game = GameBuilder::create()->withCurrentTurn($currentTurn)->persist($this->entityManager);

        return PlayerBuilder::named('Alice')->in($game)->persist($this->entityManager);
    }

    private function createValidatedOrder(Player $player, int $turn, int $total): void
    {
        $order = new Order($player, $turn);
        $order->freeze([new OrderLine('pottery', $total)], $total);
        $order->setMarking(OrderStatus::Validated->value);

        $this->entityManager->persist($order);
        $this->entityManager->flush();
    }
}
