<?php

declare(strict_types=1);

namespace Userforged\ShopEngine\Tests;

use Psr\Log\NullLogger;
use Userforged\ShopEngine\Dto\OrderLine;
use Userforged\ShopEngine\Event\OrderValidated;
use Userforged\ShopEngine\Event\ShopEventPublisher;
use Userforged\ShopEngine\Exception\EligibilityException;
use Userforged\ShopEngine\Exception\ShopExceptionReason;
use Userforged\ShopEngine\OrderStatus;
use Userforged\ShopEngine\Promotion\PromotionEngine;
use Userforged\ShopEngine\Service\LineQuoter;
use Userforged\ShopEngine\Service\OrderValidator;
use Userforged\ShopEngine\Service\PriceCalculator;
use Userforged\ShopEngine\Tests\Support\FakeBuyer;
use Userforged\ShopEngine\Tests\Support\FakeBuyerProvider;
use Userforged\ShopEngine\Tests\Support\FakeFacetProvider;
use Userforged\ShopEngine\Tests\Support\FakeFulfillment;
use Userforged\ShopEngine\Tests\Support\FakeOrder;
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

final class OrderValidatorTest extends TestCase
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
    public function validatingMovesTheOrderToValidated(): void
    {
        $order = $this->pendingOrder();

        $this->validatorFor()->validate($order);

        $this->assertSame(OrderStatus::Validated, $order->status);
    }

    #[Test]
    public function validatingFreezesTheRequotedLinesAndTheirTotal(): void
    {
        $order = $this->pendingOrder();

        $this->validatorFor()->validate($order);

        $this->assertSame(['pottery', 'agriculture'], $order->keys());
        $this->assertSame(180, $order->frozenTotal);
    }

    #[Test]
    public function validatingHandsTheOrdersProductsToFulfillment(): void
    {
        $order = $this->pendingOrder();

        $this->validatorFor()->validate($order);

        $this->assertSame([['pottery', 'agriculture']], $this->fulfillment->granted);
    }

    /**
     * The buyer is resolved before the order leaves `pending`, and that
     * ordering is load-bearing: the buyer's own state feeds the elective
     * credits used to re-quote, so an order still awaiting validation must
     * never end up crediting itself.
     */
    #[Test]
    public function theBuyerIsResolvedWhileTheOrderIsStillPending(): void
    {
        $order = $this->pendingOrder();
        $statusWhenBuyerResolved = null;

        $buyers = new FakeBuyerProvider(new FakeBuyer(), function () use ($order, &$statusWhenBuyerResolved): void {
            $statusWhenBuyerResolved = $order->status;
        });

        $this->validatorFor($buyers)->validate($order);

        $this->assertSame(OrderStatus::Pending, $statusWhenBuyerResolved);
    }

    /**
     * The validation event is published through afterCommit(), never straight
     * after the inner scope returns. When validate() runs inside an enclosing
     * scope, publishing eagerly would let a consumer observe a validated
     * order before the write that validated it had actually landed.
     */
    #[Test]
    public function theValidationEventIsWithheldUntilTheEnclosingScopeCommits(): void
    {
        $order = $this->pendingOrder();
        $validator = $this->validatorFor();
        $eventsSeenInsideTheScope = null;

        $this->transaction->transactional(function () use ($validator, $order, &$eventsSeenInsideTheScope): void {
            $validator->validate($order);

            $eventsSeenInsideTheScope = $this->eventDispatcher->events;
        });

        $this->assertSame([], $eventsSeenInsideTheScope);
        $this->assertCount(1, $this->eventDispatcher->ofType(OrderValidated::class));
    }

    #[Test]
    public function validatingPublishesTheValidationEvent(): void
    {
        $buyerId = new UuidV4();
        $order = $this->pendingOrder($buyerId);

        $this->validatorFor()->validate($order);

        $published = $this->eventDispatcher->ofType(OrderValidated::class);
        $this->assertCount(1, $published);
        $this->assertTrue($published[0]->buyerId->equals($buyerId));
        $this->assertSame(7, $published[0]->window);
    }

    #[Test]
    public function validatingAnOrderHoldingAProductTheBuyerAlreadyOwnsIsRefused(): void
    {
        $order = $this->pendingOrder();

        try {
            $this->validatorFor(new FakeBuyerProvider(new FakeBuyer(['agriculture'])))->validate($order);
            $this->fail('Expected an owned product to be refused.');
        } catch (EligibilityException $e) {
            $this->assertSame(ShopExceptionReason::ProductAlreadyOwned, $e->reason());
            $this->assertSame(['key' => 'agriculture'], $e->context());
        }

        $this->assertSame(OrderStatus::Pending, $order->status);
        $this->assertSame([], $this->fulfillment->granted);
        $this->assertSame([], $this->eventDispatcher->events);
    }

    private function pendingOrder(?UuidV4 $buyerId = null): FakeOrder
    {
        return new FakeOrder(
            $buyerId ?? new UuidV4(),
            window: 7,
            lines: [new OrderLine('pottery', 60), new OrderLine('agriculture', 120)],
        );
    }

    private function validatorFor(?FakeBuyerProvider $buyers = null): OrderValidator
    {
        return new OrderValidator(
            $this->transaction,
            $this->lineQuoter(),
            ShopOrderStateMachine::create(),
            $buyers ?? new FakeBuyerProvider(),
            new ShopEventPublisher($this->eventDispatcher, new RecordingMessageBus(), new NullLogger()),
            $this->fulfillment,
        );
    }

    private function lineQuoter(): LineQuoter
    {
        $catalog = [
            new FakeProduct(key: 'pottery', cost: 60),
            new FakeProduct(key: 'agriculture', cost: 120),
        ];

        return new LineQuoter(
            new FakeProductProvider($catalog),
            new PriceCalculator(new FakePriceResolver()),
            new PromotionEngine(),
            new FakeFacetProvider(['craft', 'science']),
        );
    }
}
