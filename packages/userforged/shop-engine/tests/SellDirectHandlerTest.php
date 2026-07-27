<?php

declare(strict_types=1);

namespace Userforged\ShopEngine\Tests;

use Psr\Log\NullLogger;
use Userforged\ShopEngine\Command\SellDirect;
use Userforged\ShopEngine\CommandHandler\SellDirectHandler;
use Userforged\ShopEngine\Dto\LineIntent;
use Userforged\ShopEngine\Event\OrderSold;
use Userforged\ShopEngine\Event\OrderValidated;
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
use Userforged\ShopEngine\Service\OrderValidator;
use Userforged\ShopEngine\Service\PriceCalculator;
use Userforged\ShopEngine\Tests\Support\FakeBuyer;
use Userforged\ShopEngine\Tests\Support\FakeBuyerProvider;
use Userforged\ShopEngine\Tests\Support\FakeFacetProvider;
use Userforged\ShopEngine\Tests\Support\FakeFulfillment;
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

/**
 * A direct sale is the counter case: no pending step, the buyer pays and
 * walks away with the goods, so a single command has to submit and validate
 * at once. Everything the validator guarantees still has to hold.
 */
final class SellDirectHandlerTest extends TestCase
{
    private const int WINDOW = 5;

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
    public function sellingAnEmptyCartIsRefused(): void
    {
        $orders = new FakeOrderRepository();

        try {
            $this->handlerFor($orders)(new SellDirect(new UuidV4(), [], self::WINDOW));
            $this->fail('Expected an empty cart to be refused.');
        } catch (CartException $e) {
            $this->assertSame(ShopExceptionReason::CartEmpty, $e->reason());
        }

        $this->assertSame(0, $orders->created);
    }

    #[Test]
    public function sellingIntoAWindowThatAlreadyHoldsAValidatedOrderIsRefused(): void
    {
        $buyerId = new UuidV4();
        $orders = new FakeOrderRepository([new FakeOrder($buyerId, self::WINDOW, 'validated')]);

        try {
            $this->handlerFor($orders)(new SellDirect($buyerId, [new LineIntent('pottery')], self::WINDOW));
            $this->fail('Expected a validated window to be refused.');
        } catch (OrderException $e) {
            $this->assertSame(ShopExceptionReason::WindowAlreadyValidated, $e->reason());
        }
    }

    #[Test]
    public function sellingProducesAnOrderThatIsAlreadyValidatedAndFrozen(): void
    {
        $orders = new FakeOrderRepository();

        $order = $this->handlerFor($orders)(new SellDirect(new UuidV4(), [new LineIntent('pottery'), new LineIntent('agriculture')], self::WINDOW));

        $this->assertSame(1, $orders->created);
        $this->assertSame(OrderStatus::Validated, $order->status);
        $this->assertSame(['pottery', 'agriculture'], $order->keys());
        $this->assertSame(180, $order->frozenTotal);
    }

    #[Test]
    public function sellingHandsTheProductsToFulfillment(): void
    {
        $this->handlerFor(new FakeOrderRepository())(new SellDirect(new UuidV4(), [new LineIntent('pottery')], self::WINDOW));

        $this->assertSame([['pottery']], $this->fulfillment->granted);
    }

    /**
     * Both events fire, and their order is the contract: a listener reacting
     * to the sale can rely on the validation having already been announced.
     */
    #[Test]
    public function sellingAnnouncesTheValidationBeforeTheSale(): void
    {
        $this->handlerFor(new FakeOrderRepository())(new SellDirect(new UuidV4(), [new LineIntent('pottery')], self::WINDOW));

        $this->assertCount(1, $this->eventDispatcher->ofType(OrderValidated::class));
        $this->assertCount(1, $this->eventDispatcher->ofType(OrderSold::class));
        $this->assertInstanceOf(OrderValidated::class, $this->eventDispatcher->events[0]);
        $this->assertInstanceOf(OrderSold::class, $this->eventDispatcher->events[1]);
    }

    #[Test]
    public function sellingOntoARejectedWindowReopensItAndValidatesIt(): void
    {
        $buyerId = new UuidV4();
        $rejected = new FakeOrder($buyerId, self::WINDOW, 'rejected');

        $order = $this->handlerFor(new FakeOrderRepository([$rejected]))(new SellDirect($buyerId, [new LineIntent('pottery')], self::WINDOW));

        $this->assertSame($rejected, $order);
        $this->assertSame(OrderStatus::Validated, $order->status);
    }

    /**
     * SellDirectHandler runs no eligibility check of its own — it leans on the
     * validator's. This pins that the delegation actually happens, rather than
     * a direct sale quietly becoming the way to buy something twice.
     */
    #[Test]
    public function sellingAProductTheBuyerAlreadyOwnsIsRefusedByTheValidator(): void
    {
        $orders = new FakeOrderRepository();

        try {
            $this->handlerFor($orders, ['pottery'])(new SellDirect(new UuidV4(), [new LineIntent('pottery')], self::WINDOW));
            $this->fail('Expected an owned product to be refused.');
        } catch (EligibilityException $e) {
            $this->assertSame(ShopExceptionReason::ProductAlreadyOwned, $e->reason());
        }

        $this->assertSame([], $this->fulfillment->granted);
        $this->assertSame([], $this->eventDispatcher->events);
    }

    #[Test]
    public function aRejectedQuoteLeavesNoOrderBehind(): void
    {
        $orders = new FakeOrderRepository();

        try {
            $this->handlerFor($orders)(new SellDirect(new UuidV4(), [new LineIntent('monument')], self::WINDOW));
            $this->fail('Expected the incomplete allocation to be refused.');
        } catch (PromotionException) {
        }

        $this->assertSame(0, $orders->created);
        $this->assertSame(0, $this->transaction->committedScopes);
        $this->assertSame([], $this->eventDispatcher->events);
    }

    /**
     * The handler deliberately opens no scope of its own: its in-memory
     * mutations ride into the validator's single scope through the unit of
     * work, which keeps every exception the validator can raise outside any
     * open transaction.
     */
    #[Test]
    public function theWholeSaleCommitsInOneScope(): void
    {
        $this->handlerFor(new FakeOrderRepository())(new SellDirect(new UuidV4(), [new LineIntent('pottery')], self::WINDOW));

        $this->assertSame(1, $this->transaction->committedScopes);
    }

    /** @param list<string> $ownedKeys */
    private function handlerFor(FakeOrderRepository $orders, array $ownedKeys = []): SellDirectHandler
    {
        $buyers = new FakeBuyerProvider(new FakeBuyer($ownedKeys));
        $publisher = new ShopEventPublisher($this->eventDispatcher, new RecordingMessageBus(), new NullLogger());
        $stateMachine = ShopOrderStateMachine::create();

        $validator = new OrderValidator(
            $this->transaction,
            $this->lineQuoter(),
            $stateMachine,
            $buyers,
            $publisher,
            $this->fulfillment,
        );

        return new SellDirectHandler(
            $orders,
            $validator,
            $this->lineQuoter(),
            $stateMachine,
            $publisher,
            $buyers,
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
