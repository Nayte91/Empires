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

final class PurchaseValueTest extends WebTestCase
{
    use GameFixtureTrait;
    use InteractsWithTwigComponents;

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
    public function aGameTooShortToAverageHasNoAverageAtAll(): void
    {
        $player = PlayerBuilder::named('Alice')->in(GameBuilder::create()->withCurrentTurn(PurchaseHistoryCalculator::AVERAGE_FROM_TURN - 1)->persist($this->entityManager))->persist($this->entityManager);

        $this->assertNull($this->mountPurchaseValue($player)->getAverage());
    }

    #[Test]
    public function aPlayerWhoBoughtNothingInALongGameAveragesAGenuineZero(): void
    {
        $player = PlayerBuilder::named('Alice')->in(GameBuilder::create()->withCurrentTurn(20)->persist($this->entityManager))->persist($this->entityManager);

        $average = $this->mountPurchaseValue($player)->getAverage();

        // Rector rewrites a float assertSame into assertEqualsWithDelta, which accepts null as 0.0 —
        // the collapse this test exists to forbid.
        $this->assertNotNull($average);
        $this->assertEqualsWithDelta(0.0, $average, PHP_FLOAT_EPSILON);
    }

    #[Test]
    public function theAverageIsTheSpendPerTurnOfPlay(): void
    {
        $player = PlayerBuilder::named('Alice')->in(GameBuilder::create()->withCurrentTurn(10)->persist($this->entityManager))->persist($this->entityManager);
        OrderBuilder::for($player)->onTurn(6)->withLine(new OrderLine('pottery', 100))->validated(100)->persist($this->entityManager);
        OrderBuilder::for($player)->onTurn(8)->withLine(new OrderLine('pottery', 200))->validated(200)->persist($this->entityManager);

        $this->assertEqualsWithDelta(60.0, $this->mountPurchaseValue($player)->getAverage(), PHP_FLOAT_EPSILON);
    }

    private function mountPurchaseValue(Player $player): PurchaseValue
    {
        $component = $this->mountTwigComponent('PurchaseValue', ['player' => $player]);
        $this->assertInstanceOf(PurchaseValue::class, $component);

        return $component;
    }
}
