<?php

declare(strict_types=1);

namespace App\Tests\Shop;

use App\Entity\GameSession;
use App\Entity\Player;
use App\Game\AdvanceCatalog;
use App\Game\ScenarioCatalog;
use App\Game\Shop\AdvanceFulfillment;
use App\Game\Shop\AdvancePriceResolver;
use App\Game\Shop\PlayerBuyerProvider;
use App\Game\Shop\ShopConnector;
use App\Repository\OrderRepository;
use App\Repository\PlayerRepository;
use Userforged\ShopEngine\Command\SubmitOrder;
use Userforged\ShopEngine\CommandHandler\SubmitOrderHandler;
use Userforged\ShopEngine\Doctrine\DoctrineTransaction;
use Userforged\ShopEngine\Dto\LineIntent;
use Userforged\ShopEngine\Dto\OrderLine;
use Userforged\ShopEngine\Event\ShopEventPublisher;
use Userforged\ShopEngine\Exception\CartException;
use Userforged\ShopEngine\Exception\EligibilityException;
use Userforged\ShopEngine\Exception\OrderException;
use Userforged\ShopEngine\OrderStatus;
use Userforged\ShopEngine\ProductProviderInterface;
use Userforged\ShopEngine\Promotion\AppliedPromotion;
use Userforged\ShopEngine\Promotion\PromotionEngine;
use Userforged\ShopEngine\Promotion\PromotionType;
use Userforged\ShopEngine\Service\LineQuoter;
use Userforged\ShopEngine\Service\OrderValidator;
use Userforged\ShopEngine\Service\PriceCalculator;
use App\Tests\Support\Mercure\RecordingHub;
use App\Tests\Support\Workflow\ShopOrderStateMachine;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class OrderFlowTest extends WebTestCase
{
    private EntityManagerInterface $entityManager;
    private OrderRepository $orderRepository;
    private SubmitOrderHandler $submitOrderHandler;
    private OrderValidator $orderValidator;
    private RecordingHub $hub;

    protected function setUp(): void
    {
        self::bootKernel();

        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->orderRepository = self::getContainer()->get(OrderRepository::class);
        $playerRepository = self::getContainer()->get(PlayerRepository::class);
        $productProvider = self::getContainer()->get(ProductProviderInterface::class);
        $advanceCatalog = self::getContainer()->get(AdvanceCatalog::class);
        $scenarioCatalog = self::getContainer()->get(ScenarioCatalog::class);
        $this->hub = self::getContainer()->get(RecordingHub::class);

        // SubmitOrderHandler and OrderValidator are built by hand rather than fetched
        // from the container. This wires them to the guard-free ShopOrderStateMachine
        // test double (tests/Support/Workflow/ShopOrderStateMachine.php) instead of the
        // container's registered shop_order workflow, which carries OrderWorkflowPolicy's
        // guard on reject. This file never exercises reject, so it's inherited convention
        // from RejectOrderTest (the sibling file where it's load-bearing) rather than a
        // hard requirement here — worth revisiting. Built from the shared EntityManager /
        // OrderRepository / PlayerRepository / ProductProviderInterface instances.
        $shopConnector = new ShopConnector($this->orderRepository, $advanceCatalog, $scenarioCatalog);
        $lineQuoter = new LineQuoter($productProvider, new PriceCalculator(new AdvancePriceResolver()), new PromotionEngine(), $shopConnector);
        $shopOrderStateMachine = ShopOrderStateMachine::create();
        $eventBus = self::getContainer()->get(ShopEventPublisher::class);
        $fulfillment = new AdvanceFulfillment($playerRepository);
        $buyerProvider = new PlayerBuyerProvider($playerRepository, $shopConnector);
        // Single shared DoctrineTransaction instance: this file also drives
        // OrderValidator::validate() directly, standalone, so it doesn't need to
        // join anything here — but every handler/service built in this suite must
        // still share one instance for the depth counter to mean anything.
        $transaction = new DoctrineTransaction($this->entityManager);
        $this->submitOrderHandler = new SubmitOrderHandler(
            $transaction,
            $this->orderRepository,
            $lineQuoter,
            $shopOrderStateMachine,
            $eventBus,
            $buyerProvider,
        );
        $this->orderValidator = new OrderValidator(
            $transaction,
            $lineQuoter,
            $shopOrderStateMachine,
            $buyerProvider,
            $eventBus,
            $fulfillment,
        );
    }

    #[Test]
    public function submitWithEmptyCartThrowsCartException(): void
    {
        $player = $this->createPlayer();

        $this->expectException(CartException::class);

        ($this->submitOrderHandler)(new SubmitOrder($player->id, $this->intents([]), $player->game->currentTurn));
    }

    #[Test]
    public function submitCreatesPendingOrderWithSlugs(): void
    {
        $player = $this->createPlayer();

        $order = ($this->submitOrderHandler)(new SubmitOrder($player->id, $this->intents(['pottery', 'agriculture']), $player->game->currentTurn));

        $this->assertSame(OrderStatus::Pending, $order->status);
        $this->assertSame(['pottery', 'agriculture'], $order->keys());
    }

    #[Test]
    public function resubmitReplacesLinesOnSameOrderAndKeepsUniqueRow(): void
    {
        $player = $this->createPlayer();
        $firstOrder = ($this->submitOrderHandler)(new SubmitOrder($player->id, $this->intents(['pottery']), $player->game->currentTurn));

        $secondOrder = ($this->submitOrderHandler)(new SubmitOrder($player->id, $this->intents(['democracy']), $player->game->currentTurn));

        $this->assertSame($firstOrder->id->toRfc4122(), $secondOrder->id->toRfc4122());
        $this->assertSame(['democracy'], $secondOrder->keys());
        $this->assertSame(1, $this->orderRepository->count([
            'player' => $player,
            'turn' => $player->game->currentTurn,
        ]));
    }

    #[Test]
    public function validateFreezesLinesAndOwnsAdvances(): void
    {
        $player = $this->createPlayer();
        $player->ownAdvances(['agriculture']);
        $this->entityManager->flush();

        $order = ($this->submitOrderHandler)(new SubmitOrder($player->id, $this->intents(['democracy']), $player->game->currentTurn));

        $this->orderValidator->validate($order);

        $this->assertSame(OrderStatus::Validated, $order->status);
        $this->assertEquals([new OrderLine('democracy', 200)], $order->lines);
        $this->assertSame(200, $order->total);
        $this->assertContains('democracy', $player->advances);
    }

    #[Test]
    public function submitWithLibraryDiscountsTheOtherLineAndPersistsThePromotionPayload(): void
    {
        $player = $this->createPlayer();

        $order = ($this->submitOrderHandler)(new SubmitOrder($player->id, $this->intents(['library', 'democracy']), $player->game->currentTurn));

        $democracyLine = $order->lines()[1];
        $this->assertSame('democracy', $democracyLine->key);
        $this->assertSame(180, $democracyLine->netCost);
        $this->assertInstanceOf(AppliedPromotion::class, $democracyLine->promotion);
        $this->assertSame(PromotionType::Discount, $democracyLine->promotion->type);
        $this->assertSame('library', $democracyLine->promotion->source);
        $this->assertSame(40, $democracyLine->promotion->amount);
    }

    #[Test]
    public function validatingALibraryAndDemocracyOrderFreezesTheDiscountedTotal(): void
    {
        $player = $this->createPlayer();
        $order = ($this->submitOrderHandler)(new SubmitOrder($player->id, $this->intents(['library', 'democracy']), $player->game->currentTurn));

        $this->orderValidator->validate($order);

        $this->assertSame(400, $order->total);
        $democracyLine = $order->lines()[1];
        $this->assertSame(180, $democracyLine->netCost);
        $this->assertInstanceOf(AppliedPromotion::class, $democracyLine->promotion);
    }

    #[Test]
    public function submitAfterValidationOfTheTurnThrowsOrderException(): void
    {
        $player = $this->createPlayer();
        $order = ($this->submitOrderHandler)(new SubmitOrder($player->id, $this->intents(['pottery']), $player->game->currentTurn));
        $this->orderValidator->validate($order);

        $this->expectException(OrderException::class);

        ($this->submitOrderHandler)(new SubmitOrder($player->id, $this->intents(['democracy']), $player->game->currentTurn));
    }

    #[Test]
    public function submitWithAlreadyOwnedAdvanceInCartThrowsEligibilityException(): void
    {
        $player = $this->createPlayer();
        $player->ownAdvances(['pottery']);
        $this->entityManager->flush();

        $this->expectException(EligibilityException::class);
        $this->expectExceptionMessageMatches('/pottery/');

        ($this->submitOrderHandler)(new SubmitOrder($player->id, $this->intents(['pottery']), $player->game->currentTurn));
    }

    #[Test]
    public function submittingAnOrderPublishesOrderUpdatedOnTheGameTopic(): void
    {
        $player = $this->createPlayer();

        ($this->submitOrderHandler)(new SubmitOrder($player->id, $this->intents(['pottery']), $player->game->currentTurn));

        $this->assertSame(['order-updated'], $this->hub->eventNames());
        $this->assertSame(['empires/game/'.$player->game->id], $this->hub->topics());
    }

    #[Test]
    public function validatingAnOrderPublishesOrderUpdatedThenPlayerUpdated(): void
    {
        $player = $this->createPlayer();
        $order = ($this->submitOrderHandler)(new SubmitOrder($player->id, $this->intents(['pottery']), $player->game->currentTurn));
        $this->hub->clear();

        $this->orderValidator->validate($order);

        $this->assertSame(['order-updated', 'player-updated'], $this->hub->eventNames());
        $topic = 'empires/game/'.$player->game->id;
        $this->assertSame([$topic, $topic], $this->hub->topics());
    }

    private function createPlayer(): Player
    {
        $game = new GameSession();
        $player = new Player($game, 'Alice');

        $this->entityManager->persist($game);
        $this->entityManager->persist($player);
        $this->entityManager->flush();

        return $player;
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
