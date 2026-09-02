<?php

declare(strict_types=1);

namespace App\Tests\Integration\ShopFlow;

use App\Engine\Shop\AdvanceFulfillment;
use App\Infrastructure\Repository\OrderRepository;
use App\State\Order;
use App\Tests\Support\Fixture\PlayerBuilder;
use App\Tests\Support\GameFixtureTrait;
use App\Tests\Support\ShopFixtureTrait;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Userforged\ShopEngine\Command\SellDirect;
use Userforged\ShopEngine\Command\SubmitOrder;
use Userforged\ShopEngine\CommandHandler\SellDirectHandler;
use Userforged\ShopEngine\CommandHandler\SubmitOrderHandler;
use Userforged\ShopEngine\Dto\OrderLine;
use Userforged\ShopEngine\Exception\OrderException;
use Userforged\ShopEngine\OrderStatus;

final class DirectSaleTest extends WebTestCase
{
    use GameFixtureTrait;
    use ShopFixtureTrait;

    private OrderRepository $orderRepository;
    private SubmitOrderHandler $submitOrderHandler;
    private SellDirectHandler $sellDirectHandler;
    private AdvanceFulfillment $fulfillment;

    protected function setUp(): void
    {
        $this->initEntityManager();

        $this->orderRepository = self::getContainer()->get(OrderRepository::class);
        $this->submitOrderHandler = self::getContainer()->get(SubmitOrderHandler::class);
        $this->sellDirectHandler = self::getContainer()->get(SellDirectHandler::class);
        $this->fulfillment = self::getContainer()->get(AdvanceFulfillment::class);
    }

    #[Test]
    public function sellValidatesOrderImmediatelyAndOwnsAdvances(): void
    {
        $player = PlayerBuilder::named('Alice')->persist($this->entityManager);
        $this->fulfillment->grant($player->id, ['agriculture']);
        $this->entityManager->flush();

        $order = ($this->sellDirectHandler)(new SellDirect($player->id, $this->intents(['democracy', 'pottery']), $player->game->currentTurn));

        $this->assertSame(OrderStatus::Validated, $order->status);
        $this->assertEquals([new OrderLine('democracy', 200), new OrderLine('pottery', 50)], $order->lines);
        $this->assertSame(250, $order->total);
        $this->assertContains('democracy', $player->advances);
        $this->assertContains('pottery', $player->advances);

        $this->entityManager->clear();
        $reloadedOrder = $this->orderRepository->find($order->id);
        $this->assertInstanceOf(Order::class, $reloadedOrder);
        $this->assertSame(OrderStatus::Validated, $reloadedOrder->status);
    }

    #[Test]
    public function sellWithExplicitPastTurnCreatesAndValidatesTheOrderOnThatTurn(): void
    {
        $player = PlayerBuilder::named('Alice')->persist($this->entityManager);
        $player->game->currentTurn = 3;
        $this->entityManager->flush();

        $order = ($this->sellDirectHandler)(new SellDirect($player->id, $this->intents(['pottery']), 1));

        $this->assertSame(1, $order->turn);
        $this->assertSame(OrderStatus::Validated, $order->status);
        $this->assertContains('pottery', $player->advances);
    }

    #[Test]
    public function sellReusesExistingPendingOrderRow(): void
    {
        $player = PlayerBuilder::named('Alice')->persist($this->entityManager);
        $pendingOrder = ($this->submitOrderHandler)(new SubmitOrder($player->id, $this->intents(['pottery']), $player->game->currentTurn));

        $order = ($this->sellDirectHandler)(new SellDirect($player->id, $this->intents(['democracy']), $player->game->currentTurn));

        $this->assertSame($pendingOrder->id->toRfc4122(), $order->id->toRfc4122());
        $this->assertSame(1, $this->orderRepository->count([
            'player' => $player,
            'turn' => $player->game->currentTurn,
        ]));
        $this->assertEquals([new OrderLine('democracy', 220)], $order->lines);
    }

    #[Test]
    public function sellAfterValidationOfTheTurnThrowsOrderException(): void
    {
        $player = PlayerBuilder::named('Alice')->persist($this->entityManager);
        ($this->sellDirectHandler)(new SellDirect($player->id, $this->intents(['pottery']), $player->game->currentTurn));

        $this->expectException(OrderException::class);

        ($this->sellDirectHandler)(new SellDirect($player->id, $this->intents(['democracy']), $player->game->currentTurn));
    }
}
