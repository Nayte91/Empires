<?php

declare(strict_types=1);

namespace Userforged\ShopEngine\Tests;

use Psr\Log\NullLogger;
use Userforged\ShopEngine\Command\RejectOrder;
use Userforged\ShopEngine\CommandHandler\RejectOrderHandler;
use Userforged\ShopEngine\Event\OrderRejected;
use Userforged\ShopEngine\Event\ShopEventPublisher;
use Userforged\ShopEngine\Exception\OrderException;
use Userforged\ShopEngine\Exception\ShopExceptionReason;
use Userforged\ShopEngine\OrderStatus;
use Userforged\ShopEngine\Tests\Support\FakeOrder;
use Userforged\ShopEngine\Tests\Support\FakeOrderRepository;
use Userforged\ShopEngine\Tests\Support\FakeTransaction;
use Userforged\ShopEngine\Tests\Support\RecordingEventDispatcher;
use Userforged\ShopEngine\Tests\Support\RecordingMessageBus;
use Userforged\ShopEngine\Tests\Support\ShopOrderStateMachine;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Uid\UuidV4;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Rejection is engine behaviour with no consumer today — the reference host
 * deleted its own reject flow entirely. That is exactly why it is tested
 * here: a shipped capability nobody currently calls is the one most likely to
 * rot unnoticed, and the next consumer will find it in the public surface and
 * expect it to work.
 */
final class RejectOrderHandlerTest extends TestCase
{
    private const int WINDOW = 3;

    private FakeTransaction $transaction;

    private RecordingEventDispatcher $eventDispatcher;

    protected function setUp(): void
    {
        $this->transaction = new FakeTransaction();
        $this->eventDispatcher = new RecordingEventDispatcher();
    }

    #[Test]
    public function rejectingAPendingOrderMovesItToRejected(): void
    {
        $buyerId = new UuidV4();
        $order = new FakeOrder($buyerId, self::WINDOW);

        $this->handlerFor($order)(new RejectOrder($buyerId, self::WINDOW));

        $this->assertSame(OrderStatus::Rejected, $order->status);
    }

    #[Test]
    public function rejectingAPendingOrderPublishesTheRejectionEvent(): void
    {
        $buyerId = new UuidV4();
        $order = new FakeOrder($buyerId, self::WINDOW);

        $this->handlerFor($order)(new RejectOrder($buyerId, self::WINDOW));

        $published = $this->eventDispatcher->ofType(OrderRejected::class);
        $this->assertCount(1, $published);
        $this->assertTrue($published[0]->buyerId->equals($buyerId));
        $this->assertSame(self::WINDOW, $published[0]->window);
    }

    #[Test]
    public function theRejectionTransitionRunsInsideOneTransactionalScope(): void
    {
        $buyerId = new UuidV4();
        $order = new FakeOrder($buyerId, self::WINDOW);

        $this->handlerFor($order)(new RejectOrder($buyerId, self::WINDOW));

        $this->assertSame(1, $this->transaction->committedScopes);
    }

    /**
     * A window a buyer never ordered in is not an error: the operator asking
     * to reject it simply has nothing to reject. Turning this into an
     * exception would make an idempotent retry fail.
     */
    #[Test]
    public function rejectingAnOrderThatDoesNotExistIsASilentNoOp(): void
    {
        $handler = new RejectOrderHandler(
            $this->transaction,
            new FakeOrderRepository(),
            ShopOrderStateMachine::create(),
            $this->publisher(),
        );

        $handler(new RejectOrder(new UuidV4(), self::WINDOW));

        $this->assertSame(0, $this->transaction->committedScopes);
        $this->assertSame([], $this->eventDispatcher->events);
    }

    /**
     * config/workflow.yaml opens `reject` from `pending` only. This is the
     * law that test asserts — not the handler's own `if`, which merely
     * translates the state machine's refusal into a domain exception.
     */
    #[Test]
    #[DataProvider('provideOnlyAPendingOrderCanBeRejectedCases')]
    public function onlyAPendingOrderCanBeRejected(string $marking): void
    {
        $buyerId = new UuidV4();
        $order = new FakeOrder($buyerId, self::WINDOW, $marking);

        try {
            $this->handlerFor($order)(new RejectOrder($buyerId, self::WINDOW));
            $this->fail('Expected the rejection to be refused.');
        } catch (OrderException $e) {
            $this->assertSame(ShopExceptionReason::OrderRejectionUnavailable, $e->reason());
        }

        $this->assertSame([], $this->eventDispatcher->events);
    }

    public static function provideOnlyAPendingOrderCanBeRejectedCases(): iterable
    {
        yield 'an order already validated' => ['validated'];

        yield 'an order already rejected' => ['rejected'];
    }

    #[Test]
    public function aRefusedRejectionLeavesTheOrderUntouched(): void
    {
        $buyerId = new UuidV4();
        $order = new FakeOrder($buyerId, self::WINDOW, 'validated');

        try {
            $this->handlerFor($order)(new RejectOrder($buyerId, self::WINDOW));
        } catch (OrderException) {
        }

        $this->assertSame(OrderStatus::Validated, $order->status);
        $this->assertSame(0, $this->transaction->committedScopes);
    }

    #[Test]
    public function anOrderInAnotherWindowIsNeverRejected(): void
    {
        $buyerId = new UuidV4();
        $order = new FakeOrder($buyerId, self::WINDOW);

        $this->handlerFor($order)(new RejectOrder($buyerId, self::WINDOW + 1));

        $this->assertSame(OrderStatus::Pending, $order->status);
        $this->assertSame([], $this->eventDispatcher->events);
    }

    #[Test]
    public function anotherBuyersOrderInTheSameWindowIsNeverRejected(): void
    {
        $order = new FakeOrder(new UuidV4(), self::WINDOW);

        $this->handlerFor($order)(new RejectOrder(new UuidV4(), self::WINDOW));

        $this->assertSame(OrderStatus::Pending, $order->status);
        $this->assertSame([], $this->eventDispatcher->events);
    }

    private function handlerFor(FakeOrder $order): RejectOrderHandler
    {
        return new RejectOrderHandler(
            $this->transaction,
            new FakeOrderRepository([$order]),
            ShopOrderStateMachine::create(),
            $this->publisher(),
        );
    }

    private function publisher(): ShopEventPublisher
    {
        return new ShopEventPublisher($this->eventDispatcher, new RecordingMessageBus(), new NullLogger());
    }
}
