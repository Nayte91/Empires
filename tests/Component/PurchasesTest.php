<?php

declare(strict_types=1);

namespace App\Tests\Component;

use App\Presentation\Component\Purchases;
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

final class PurchasesTest extends WebTestCase
{
    use GameFixtureTrait;
    use InteractsWithTwigComponents;

    #[Test]
    public function theSeriesPlottedStartsAtTheFirstTurnTheShopOpensWithOneTotalPerTurn(): void
    {
        $player = PlayerBuilder::named('Alice')->in(GameBuilder::create()->withCurrentTurn(8)->persist($this->entityManager))->persist($this->entityManager);
        OrderBuilder::for($player)->onTurn(7)->withLine(new OrderLine('pottery', 100))->validated(100)->persist($this->entityManager);

        $data = $this->mountPurchases($player)->getChart()->getData();

        $this->assertSame([6, 7, 8], $data['labels']);
        $this->assertSame([0, 100, 0], $data['datasets'][0]['data']);
    }

    #[Test]
    public function theAveragesRunAcrossThePlotAsConstantSeries(): void
    {
        $player = PlayerBuilder::named('Alice')->in(GameBuilder::create()->withCurrentTurn(8)->persist($this->entityManager))->persist($this->entityManager);
        OrderBuilder::for($player)->onTurn(7)->withLine(new OrderLine('pottery', 90))->validated(90)->persist($this->entityManager);

        $datasets = $this->mountPurchases($player)->getChart()->getData()['datasets'];

        $this->assertEqualsWithDelta([30.0, 30.0, 30.0], $datasets[1]['data'], PHP_FLOAT_EPSILON);
        $this->assertEqualsWithDelta([30.0, 30.0, 30.0], $datasets[2]['data'], PHP_FLOAT_EPSILON);
    }

    #[Test]
    public function aGameTooShortToAverageHasNoAverageAtAll(): void
    {
        $player = PlayerBuilder::named('Alice')->in(GameBuilder::create()->withCurrentTurn(PurchaseHistoryCalculator::AVERAGE_FROM_TURN - 1)->persist($this->entityManager))->persist($this->entityManager);

        $this->assertNull($this->mountPurchases($player)->getAverage());
    }

    #[Test]
    public function aPlayerWhoBoughtNothingInALongGameAveragesAGenuineZero(): void
    {
        $player = PlayerBuilder::named('Alice')->in(GameBuilder::create()->withCurrentTurn(20)->persist($this->entityManager))->persist($this->entityManager);

        $average = $this->mountPurchases($player)->getAverage();

        $this->assertNotNull($average);
        $this->assertEqualsWithDelta(0.0, $average, PHP_FLOAT_EPSILON);
    }

    #[Test]
    public function theAverageIsTheSpendPerTurnOfPlay(): void
    {
        $player = PlayerBuilder::named('Alice')->in(GameBuilder::create()->withCurrentTurn(10)->persist($this->entityManager))->persist($this->entityManager);
        OrderBuilder::for($player)->onTurn(6)->withLine(new OrderLine('pottery', 100))->validated(100)->persist($this->entityManager);
        OrderBuilder::for($player)->onTurn(8)->withLine(new OrderLine('pottery', 200))->validated(200)->persist($this->entityManager);

        $this->assertEqualsWithDelta(60.0, $this->mountPurchases($player)->getAverage(), PHP_FLOAT_EPSILON);
    }

    #[Test]
    public function aPlayerWhoNeverBoughtGetsASentenceInsteadOfAChart(): void
    {
        $player = PlayerBuilder::named('Alice')->in(GameBuilder::create()->withCurrentTurn(8)->persist($this->entityManager))->persist($this->entityManager);

        $rendered = $this->renderTwigComponent('Purchases', ['player' => $player])->crawler();

        $this->assertCount(0, $rendered->filter('canvas'));
        $this->assertCount(1, $rendered->filter('#purchases p'));
    }

    #[Test]
    public function aBuyerGetsTheChart(): void
    {
        $player = PlayerBuilder::named('Alice')->in(GameBuilder::create()->withCurrentTurn(8)->persist($this->entityManager))->persist($this->entityManager);
        OrderBuilder::for($player)->onTurn(7)->withLine(new OrderLine('pottery', 100))->validated(100)->persist($this->entityManager);

        $rendered = $this->renderTwigComponent('Purchases', ['player' => $player])->crawler();

        $this->assertCount(1, $rendered->filter('canvas'));
    }

    private function mountPurchases(Player $player): Purchases
    {
        $component = $this->mountTwigComponent('Purchases', ['player' => $player]);
        $this->assertInstanceOf(Purchases::class, $component);

        return $component;
    }
}
