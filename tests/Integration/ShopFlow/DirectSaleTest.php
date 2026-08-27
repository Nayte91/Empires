<?php

declare(strict_types=1);

namespace App\Tests\Integration\ShopFlow;

use App\State\Order;
use App\Rules\Ruleset\AdvanceRegistry;
use App\Engine\Shop\AdvanceFulfillment;
use App\Rules\Shop\AdvancePriceResolver;
use App\Infrastructure\Shop\PlayerBuyerProvider;
use App\Rules\Shop\ShopConnector;
use App\Infrastructure\Repository\OrderRepository;
use App\Infrastructure\Repository\PlayerRepository;
use Userforged\ShopEngine\Command\SellDirect;
use Userforged\ShopEngine\Command\SubmitOrder;
use Userforged\ShopEngine\CommandHandler\SellDirectHandler;
use Userforged\ShopEngine\CommandHandler\SubmitOrderHandler;
use Userforged\ShopEngine\Doctrine\DoctrineTransaction;
use Userforged\ShopEngine\Dto\LineIntent;
use Userforged\ShopEngine\Dto\OrderLine;
use Userforged\ShopEngine\Event\ShopEventPublisher;
use Userforged\ShopEngine\Exception\OrderException;
use Userforged\ShopEngine\OrderStatus;
use Userforged\ShopEngine\ProductProviderInterface;
use Userforged\ShopEngine\Promotion\PromotionEngine;
use Userforged\ShopEngine\Service\LineQuoter;
use Userforged\ShopEngine\Service\OrderValidator;
use Userforged\ShopEngine\Service\PriceCalculator;
use App\Tests\Support\Fixture\PlayerBuilder;
use App\Tests\Support\GameFixtureTrait;
use App\Tests\Support\Mercure\RecordingHub;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class DirectSaleTest extends WebTestCase
{
    use GameFixtureTrait;

    private OrderRepository $orderRepository;
    private SubmitOrderHandler $submitOrderHandler;
    private SellDirectHandler $sellDirectHandler;
    private AdvanceFulfillment $fulfillment;
    private RecordingHub $hub;

    protected function setUp(): void
    {
        $this->initEntityManager();

        $this->orderRepository = self::getContainer()->get(OrderRepository::class);
        $playerRepository = self::getContainer()->get(PlayerRepository::class);
        $productProvider = self::getContainer()->get(ProductProviderInterface::class);
        $advanceRegistry = self::getContainer()->get(AdvanceRegistry::class);
        $this->hub = self::getContainer()->get(RecordingHub::class);

        // SubmitOrderHandler, OrderValidator and SellDirectHandler are built by hand
        // rather than fetched from the container, so this file can drive them directly
        // and share one EntityManager/OrderRepository/PlayerRepository/
        // ProductProviderInterface with the fixtures, following OrderFlowTest's
        // convention. The shop_order workflow itself comes from the container.
        $shopConnector = new ShopConnector($this->orderRepository);
        $lineQuoter = new LineQuoter($productProvider, new PriceCalculator(new AdvancePriceResolver()), new PromotionEngine(), $shopConnector);
        $shopOrderStateMachine = self::getContainer()->get('state_machine.shop_order');
        $eventBus = self::getContainer()->get(ShopEventPublisher::class);
        $this->fulfillment = new AdvanceFulfillment($playerRepository, $advanceRegistry);
        $buyerProvider = new PlayerBuyerProvider($playerRepository, $shopConnector);
        // Single shared DoctrineTransaction instance, matching the singleton the
        // container injects in production. SellDirectHandler doesn't open a scope of
        // its own — its mutations stay unflushed and ride into OrderValidator's
        // transactional() call via the unit of work, same as today. OrderValidator
        // is the only caller that opens a scope in this flow, but sharing the
        // instance still matters: two separate instances would silently open a real
        // nested transaction (SAVEPOINT) instead of joining the moment anything ever
        // does nest.
        $transaction = new DoctrineTransaction($this->entityManager);
        $this->submitOrderHandler = new SubmitOrderHandler(
            $transaction,
            $this->orderRepository,
            $lineQuoter,
            $shopOrderStateMachine,
            $eventBus,
            $buyerProvider,
        );
        $orderValidator = new OrderValidator(
            $transaction,
            $lineQuoter,
            $shopOrderStateMachine,
            $buyerProvider,
            $eventBus,
            $this->fulfillment,
        );
        $this->sellDirectHandler = new SellDirectHandler(
            $this->orderRepository,
            $orderValidator,
            $lineQuoter,
            $shopOrderStateMachine,
            $eventBus,
            $buyerProvider,
        );
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

    #[Test]
    public function sellingDirectWakesEveryRegionThePurchaseMoved(): void
    {
        $player = PlayerBuilder::named('Alice')->persist($this->entityManager);

        ($this->sellDirectHandler)(new SellDirect($player->id, $this->intents(['pottery']), $player->game->currentTurn));

        $this->assertSame(
            ['roster', 'ast', 'operator', 'player/'.$player->id, 'player/'.$player->id.'/shop'],
            $this->hub->regions(),
        );
    }

    /**
     * @param list<string> $keys
     *
     * @return list<LineIntent>
     */
    private function intents(array $keys): array
    {
        return array_map(static fn (string $key): LineIntent => new LineIntent($key), $keys);
    }
}
