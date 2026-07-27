<?php

declare(strict_types=1);

namespace Userforged\ShopEngine\Tests;

use Psr\Log\NullLogger;
use Userforged\ShopEngine\Command\EraseOrders;
use Userforged\ShopEngine\CommandHandler\EraseOrdersHandler;
use Userforged\ShopEngine\Dto\OrderLine;
use Userforged\ShopEngine\Event\OrdersErased;
use Userforged\ShopEngine\Event\ShopEventPublisher;
use Userforged\ShopEngine\Tests\Support\FakeFulfillment;
use Userforged\ShopEngine\Tests\Support\FakeOrder;
use Userforged\ShopEngine\Tests\Support\FakeOrderRepository;
use Userforged\ShopEngine\Tests\Support\FakeTransaction;
use Userforged\ShopEngine\Tests\Support\RecordingEventDispatcher;
use Userforged\ShopEngine\Tests\Support\RecordingMessageBus;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Uid\UuidV4;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class EraseOrdersHandlerTest extends TestCase
{
    private FakeTransaction $transaction;

    private RecordingEventDispatcher $eventDispatcher;

    private FakeFulfillment $fulfillment;

    protected function setUp(): void
    {
        $this->transaction = new FakeTransaction();
        $this->eventDispatcher = new RecordingEventDispatcher();
        $this->fulfillment = new FakeFulfillment();
    }

    #[Test]
    public function erasingNoWindowAtAllIsANoOp(): void
    {
        $orders = new FakeOrderRepository();

        $this->handlerFor($orders)(new EraseOrders(new UuidV4(), []));

        $this->assertSame(0, $this->transaction->committedScopes);
        $this->assertSame([], $this->eventDispatcher->events);
    }

    #[Test]
    public function erasingWindowsThatHoldNoOrderIsANoOp(): void
    {
        $orders = new FakeOrderRepository();

        $this->handlerFor($orders)(new EraseOrders(new UuidV4(), [1, 2, 3]));

        $this->assertSame([], $orders->removed);
        $this->assertSame(0, $this->transaction->committedScopes);
        $this->assertSame([], $this->eventDispatcher->events);
    }

    #[Test]
    public function erasingRemovesEveryOrderFound(): void
    {
        $buyerId = new UuidV4();
        $orders = new FakeOrderRepository([
            new FakeOrder($buyerId, 1),
            new FakeOrder($buyerId, 2),
        ]);

        $this->handlerFor($orders)(new EraseOrders($buyerId, [1, 2]));

        $this->assertCount(2, $orders->removed);
        $this->assertSame(1, $this->transaction->committedScopes);
    }

    /**
     * Only a validated order was ever fulfilled, so only a validated order
     * has anything to give back. Revoking a pending one would strip the buyer
     * of products this order never granted.
     */
    #[Test]
    #[DataProvider('provideOnlyAValidatedOrderGivesItsProductsBackCases')]
    public function onlyAValidatedOrderGivesItsProductsBack(string $marking, array $expectedRevoked): void
    {
        $buyerId = new UuidV4();
        $order = new FakeOrder($buyerId, 4, $marking, [new OrderLine('pottery', 60)]);

        $this->handlerFor(new FakeOrderRepository([$order]))(new EraseOrders($buyerId, [4]));

        $this->assertSame($expectedRevoked, $this->fulfillment->revoked);
    }

    public static function provideOnlyAValidatedOrderGivesItsProductsBackCases(): iterable
    {
        yield 'a validated order returns what it granted' => ['validated', [['pottery']]];

        yield 'a pending order was never fulfilled' => ['pending', []];

        yield 'a rejected order was never fulfilled either' => ['rejected', []];
    }

    #[Test]
    public function theErasureEventCarriesOnlyTheWindowsThatActuallyHeldAnOrder(): void
    {
        $buyerId = new UuidV4();
        $orders = new FakeOrderRepository([
            new FakeOrder($buyerId, 1),
            new FakeOrder($buyerId, 5),
        ]);

        $this->handlerFor($orders)(new EraseOrders($buyerId, [1, 2, 5]));

        $published = $this->eventDispatcher->ofType(OrdersErased::class);
        $this->assertCount(1, $published);
        $this->assertTrue($published[0]->buyerId->equals($buyerId));
        $this->assertSame([1, 5], $published[0]->windows);
    }

    #[Test]
    public function anotherBuyersOrdersAreNeverErased(): void
    {
        $orders = new FakeOrderRepository([new FakeOrder(new UuidV4(), 1)]);

        $this->handlerFor($orders)(new EraseOrders(new UuidV4(), [1]));

        $this->assertSame([], $orders->removed);
        $this->assertSame([], $this->eventDispatcher->events);
    }

    private function handlerFor(FakeOrderRepository $orders): EraseOrdersHandler
    {
        return new EraseOrdersHandler(
            $this->transaction,
            $orders,
            new ShopEventPublisher($this->eventDispatcher, new RecordingMessageBus(), new NullLogger()),
            $this->fulfillment,
        );
    }
}
