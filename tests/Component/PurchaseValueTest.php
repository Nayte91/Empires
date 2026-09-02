<?php

declare(strict_types=1);

namespace App\Tests\Component;

use App\Presentation\Component\PurchaseValue;
use App\Rules\PurchaseHistoryCalculator;
use App\State\Player;
use App\Tests\Support\Fixture\GameBuilder;
use App\Tests\Support\Fixture\OrderBuilder;
use App\Tests\Support\Fixture\PlayerBuilder;
use App\Tests\Support\GameFixtureTrait;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\UX\TwigComponent\Test\InteractsWithTwigComponents;
use Userforged\ShopEngine\Dto\OrderLine;

/**
 * An average of zero and no average at all are different facts, and one attribute is all that
 * separates them in the markup: assert the wrong one and both states read alike.
 */
final class PurchaseValueTest extends WebTestCase
{
    use GameFixtureTrait;
    use InteractsWithTwigComponents;

    #[Test]
    public function theCanvasTakesItsHeightFromItsContainerRatherThanFromAFixedRatio(): void
    {
        $player = PlayerBuilder::named('Alice')->in(GameBuilder::create()->withCurrentTurn(6)->persist($this->entityManager))->persist($this->entityManager);

        $options = $this->mountPurchaseValue($player)->getChart()->getOptions();

        $this->assertFalse($options['maintainAspectRatio']);
    }

    #[Test]
    public function theSeriesPlottedIsOneTotalPerTurnOfTheGame(): void
    {
        $player = PlayerBuilder::named('Alice')->in(GameBuilder::create()->withCurrentTurn(4)->persist($this->entityManager))->persist($this->entityManager);
        OrderBuilder::for($player)->onTurn(2)->withLine(new OrderLine('pottery', 100))->validated(100)->persist($this->entityManager);

        $data = $this->mountPurchaseValue($player)->getChart()->getData();

        $this->assertSame([1, 2, 3, 4], $data['labels']);
        $this->assertCount(1, $data['datasets']);
        $this->assertSame([0, 100, 0, 0], $data['datasets'][0]['data']);
    }

    #[Test]
    public function aGameTooShortToAverageSaysSoRatherThanShowingAZero(): void
    {
        $player = PlayerBuilder::named('Alice')->in(GameBuilder::create()->withCurrentTurn(PurchaseHistoryCalculator::AVERAGE_FROM_TURN - 1)->persist($this->entityManager))->persist($this->entityManager);

        $crawler = $this->renderTwigComponent('PurchaseValue', ['player' => $player])->crawler();

        $this->assertNull($this->mountPurchaseValue($player)->getAverage());
        $this->assertCount(1, $crawler->filter('#purchase-value p[data-empty]'));
        $this->assertStringEndsWith('not enough turns played', $crawler->filter('#purchase-value p')->text());
    }

    #[Test]
    public function aPlayerWhoBoughtNothingInALongGameShowsAGenuineAverageOfZero(): void
    {
        $player = PlayerBuilder::named('Alice')->in(GameBuilder::create()->withCurrentTurn(20)->persist($this->entityManager))->persist($this->entityManager);

        $crawler = $this->renderTwigComponent('PurchaseValue', ['player' => $player])->crawler();
        $average = $this->mountPurchaseValue($player)->getAverage();

        // Rector rewrites a float assertSame into assertEqualsWithDelta, which accepts null as 0.0 —
        // the collapse this test exists to forbid.
        $this->assertNotNull($average);
        $this->assertEqualsWithDelta(0.0, $average, PHP_FLOAT_EPSILON);
        $this->assertCount(1, $crawler->filter('#purchase-value p'));
        $this->assertCount(0, $crawler->filter('#purchase-value p[data-empty]'));
        $this->assertStringEndsWith(': 0', $crawler->filter('#purchase-value p')->text());
    }

    #[Test]
    public function theAverageOnScreenIsTheSpendPerTurnOfPlay(): void
    {
        $player = PlayerBuilder::named('Alice')->in(GameBuilder::create()->withCurrentTurn(10)->persist($this->entityManager))->persist($this->entityManager);
        OrderBuilder::for($player)->onTurn(6)->withLine(new OrderLine('pottery', 100))->validated(100)->persist($this->entityManager);
        OrderBuilder::for($player)->onTurn(8)->withLine(new OrderLine('pottery', 200))->validated(200)->persist($this->entityManager);

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
}
