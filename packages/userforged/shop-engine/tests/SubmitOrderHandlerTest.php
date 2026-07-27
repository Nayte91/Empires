<?php

declare(strict_types=1);

namespace Userforged\ShopEngine\Tests;

use Psr\Log\NullLogger;
use Userforged\ShopEngine\Command\SubmitOrder;
use Userforged\ShopEngine\CommandHandler\SubmitOrderHandler;
use Userforged\ShopEngine\Dto\LineIntent;
use Userforged\ShopEngine\Event\OrderSubmitted;
use Userforged\ShopEngine\Event\ShopEventPublisher;
use Userforged\ShopEngine\Exception\CartException;
use Userforged\ShopEngine\Exception\EligibilityException;
use Userforged\ShopEngine\Exception\OrderException;
use Userforged\ShopEngine\Exception\PromotionException;
use Userforged\ShopEngine\Exception\ShopExceptionReason;
use Userforged\ShopEngine\OrderStatus;
use Userforged\ShopEngine\Promotion\ElectiveBenefit;
use Userforged\ShopEngine\Promotion\ProductPromotion;
use Userforged\ShopEngine\Promotion\PromotionEngine;
use Userforged\ShopEngine\Service\LineQuoter;
use Userforged\ShopEngine\Service\PriceCalculator;
use Userforged\ShopEngine\Tests\Support\FakeBuyer;
use Userforged\ShopEngine\Tests\Support\FakeBuyerProvider;
use Userforged\ShopEngine\Tests\Support\FakeFacetProvider;
use Userforged\ShopEngine\Tests\Support\FakeOrder;
use Userforged\ShopEngine\Tests\Support\FakeOrderRepository;
use Userforged\ShopEngine\Tests\Support\FakePriceResolver;
use Userforged\ShopEngine\Tests\Support\FakeProduct;
use Userforged\ShopEngine\Tests\Support\FakeProductProvider;
use Userforged\ShopEngine\Tests\Support\FakeTransaction;
use Userforged\ShopEngine\Tests\Support\RecordingEventDispatcher;
use Userforged\ShopEngine\Tests\Support\RecordingMessageBus;
use Userforged\ShopEngine\Tests\Support\ShopOrderStateMachine;
use Symfony\Component\Uid\UuidV4;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class SubmitOrderHandlerTest extends TestCase
{
    private const int WINDOW = 2;

    private FakeTransaction $transaction;

    private RecordingEventDispatcher $eventDispatcher;

    protected function setUp(): void
    {
        $this->transaction = new FakeTransaction();
        $this->eventDispatcher = new RecordingEventDispatcher();
    }

    #[Test]
    public function submittingAnEmptyCartIsRefused(): void
    {
        $orders = new FakeOrderRepository();

        try {
            $this->handlerFor($orders)(new SubmitOrder(new UuidV4(), [], self::WINDOW));
            $this->fail('Expected an empty cart to be refused.');
        } catch (CartException $e) {
            $this->assertSame(ShopExceptionReason::CartEmpty, $e->reason());
        }

        $this->assertSame(0, $orders->created);
    }

    #[Test]
    public function submittingAProductTheBuyerAlreadyOwnsIsRefused(): void
    {
        $orders = new FakeOrderRepository();

        try {
            $this->handlerFor($orders, ['pottery'])(new SubmitOrder(new UuidV4(), [new LineIntent('pottery')], self::WINDOW));
            $this->fail('Expected an owned product to be refused.');
        } catch (EligibilityException $e) {
            $this->assertSame(ShopExceptionReason::ProductAlreadyOwned, $e->reason());
            $this->assertSame(['key' => 'pottery'], $e->context());
        }

        $this->assertSame(0, $orders->created);
    }

    #[Test]
    public function submittingIntoAWindowThatAlreadyHoldsAValidatedOrderIsRefused(): void
    {
        $buyerId = new UuidV4();
        $orders = new FakeOrderRepository([new FakeOrder($buyerId, self::WINDOW, 'validated')]);

        try {
            $this->handlerFor($orders)(new SubmitOrder($buyerId, [new LineIntent('pottery')], self::WINDOW));
            $this->fail('Expected a validated window to be refused.');
        } catch (OrderException $e) {
            $this->assertSame(ShopExceptionReason::WindowAlreadyValidated, $e->reason());
        }
    }

    #[Test]
    public function submittingCreatesAPendingOrderCarryingTheQuotedLines(): void
    {
        $orders = new FakeOrderRepository();

        $order = $this->handlerFor($orders)(new SubmitOrder(new UuidV4(), [new LineIntent('pottery')], self::WINDOW));

        $this->assertSame(1, $orders->created);
        $this->assertSame(OrderStatus::Pending, $order->status);
        $this->assertSame(['pottery'], $order->keys());
        $this->assertSame(60, $order->lines()[0]->netCost);
    }

    #[Test]
    public function submittingAgainReusesTheExistingOrderRatherThanCreatingASecond(): void
    {
        $buyerId = new UuidV4();
        $existing = new FakeOrder($buyerId, self::WINDOW);
        $orders = new FakeOrderRepository([$existing]);

        $order = $this->handlerFor($orders)(new SubmitOrder($buyerId, [new LineIntent('agriculture')], self::WINDOW));

        $this->assertSame($existing, $order);
        $this->assertSame(0, $orders->created);
        $this->assertSame(['agriculture'], $order->keys());
    }

    /**
     * Canon rule: a rejected window is not a dead end. Submitting into it
     * reopens the slot, which is the only path back out of `rejected` the
     * state machine offers.
     */
    #[Test]
    public function submittingOntoARejectedWindowReopensIt(): void
    {
        $buyerId = new UuidV4();
        $rejected = new FakeOrder($buyerId, self::WINDOW, 'rejected');
        $orders = new FakeOrderRepository([$rejected]);

        $order = $this->handlerFor($orders)(new SubmitOrder($buyerId, [new LineIntent('pottery')], self::WINDOW));

        $this->assertSame(OrderStatus::Pending, $order->status);
        $this->assertSame(['pottery'], $order->keys());
    }

    #[Test]
    public function submittingPublishesTheSubmissionEvent(): void
    {
        $buyerId = new UuidV4();

        $this->handlerFor(new FakeOrderRepository())(new SubmitOrder($buyerId, [new LineIntent('pottery')], self::WINDOW));

        $published = $this->eventDispatcher->ofType(OrderSubmitted::class);
        $this->assertCount(1, $published);
        $this->assertTrue($published[0]->buyerId->equals($buyerId));
        $this->assertSame(self::WINDOW, $published[0]->window);
    }

    #[Test]
    public function theSubmissionRunsInsideOneTransactionalScope(): void
    {
        $this->handlerFor(new FakeOrderRepository())(new SubmitOrder(new UuidV4(), [new LineIntent('pottery')], self::WINDOW));

        $this->assertSame(1, $this->transaction->committedScopes);
    }

    /**
     * The order row is created only once the quote succeeded. Were it created
     * first, a rejected elective allocation would leave an empty order
     * persisted-but-unflushed in the unit of work, for a later flush to
     * insert as a phantom.
     */
    #[Test]
    public function aRejectedQuoteLeavesNoOrderBehind(): void
    {
        $orders = new FakeOrderRepository();

        try {
            $this->handlerFor($orders)(new SubmitOrder(new UuidV4(), [new LineIntent('monument')], self::WINDOW));
            $this->fail('Expected the incomplete allocation to be refused.');
        } catch (PromotionException) {
        }

        $this->assertSame(0, $orders->created);
        $this->assertSame(0, $this->transaction->committedScopes);
        $this->assertSame([], $this->eventDispatcher->events);
    }

    /** @param list<string> $ownedKeys */
    private function handlerFor(FakeOrderRepository $orders, array $ownedKeys = []): SubmitOrderHandler
    {
        return new SubmitOrderHandler(
            $this->transaction,
            $orders,
            $this->lineQuoter(),
            ShopOrderStateMachine::create(),
            new ShopEventPublisher($this->eventDispatcher, new RecordingMessageBus(), new NullLogger()),
            new FakeBuyerProvider(new FakeBuyer($ownedKeys)),
        );
    }

    private function lineQuoter(): LineQuoter
    {
        $catalog = [
            new FakeProduct(key: 'pottery', cost: 60),
            new FakeProduct(key: 'agriculture', cost: 120),
            new FakeProduct(key: 'monument', cost: 180, promotion: new ProductPromotion(option: new ElectiveBenefit(budget: 20, step: 5))),
        ];

        return new LineQuoter(
            new FakeProductProvider($catalog),
            new PriceCalculator(new FakePriceResolver()),
            new PromotionEngine(),
            new FakeFacetProvider(['craft', 'science']),
        );
    }
}
