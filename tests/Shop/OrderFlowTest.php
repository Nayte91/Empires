<?php

declare(strict_types=1);

namespace App\Tests\Shop;

use App\Entity\GameSession;
use App\Entity\Player;
use App\Game\AdvanceCatalog;
use App\Game\Shop\ShopConnector;
use App\Repository\OrderRepository;
use App\Repository\PlayerRepository;
use App\Shop\Command\SubmitOrder;
use App\Shop\CommandHandler\SubmitOrderHandler;
use App\Shop\Dto\LineIntent;
use App\Shop\Dto\OrderLine;
use App\Shop\Event\ShopEventPublisher;
use App\Shop\Exception\CartException;
use App\Shop\Exception\EligibilityException;
use App\Shop\Exception\OrderException;
use App\Shop\OrderStatus;
use App\Shop\Promotion\AppliedPromotion;
use App\Shop\Promotion\OptionCredits;
use App\Shop\Promotion\PromotionEngine;
use App\Shop\Promotion\PromotionType;
use App\Shop\Service\LineQuoter;
use App\Shop\Service\OrderValidator;
use App\Shop\Service\PriceCalculator;
use App\Tests\Support\Mercure\NullHub;
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

    protected function setUp(): void
    {
        self::bootKernel();

        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->orderRepository = self::getContainer()->get(OrderRepository::class);
        $playerRepository = self::getContainer()->get(PlayerRepository::class);
        $advanceCatalog = self::getContainer()->get(AdvanceCatalog::class);

        // SubmitOrderHandler and OrderValidator have no other consumer yet (the command
        // bus dispatches them by tag, not by class reference), so the compiled container
        // inlines them and they cannot be fetched directly. Build them here from the
        // shared EntityManager / OrderRepository / PlayerRepository / AdvanceCatalog instances.
        $lineQuoter = new LineQuoter($advanceCatalog, new PriceCalculator(), new PromotionEngine(), new OptionCredits($this->orderRepository));
        $shopOrderStateMachine = ShopOrderStateMachine::create();
        $shopConnector = new ShopConnector($this->orderRepository);
        $this->submitOrderHandler = new SubmitOrderHandler(
            $this->entityManager,
            $this->orderRepository,
            $playerRepository,
            new NullHub(),
            $lineQuoter,
            $shopOrderStateMachine,
            self::getContainer()->get(ShopEventPublisher::class),
            $shopConnector,
        );
        $this->orderValidator = new OrderValidator(
            $this->entityManager,
            $lineQuoter,
            new NullHub(),
            $shopOrderStateMachine,
            $shopConnector,
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

        self::assertSame(OrderStatus::Pending, $order->status);
        self::assertSame(['pottery', 'agriculture'], $order->keys());
    }

    #[Test]
    public function resubmitReplacesLinesOnSameOrderAndKeepsUniqueRow(): void
    {
        $player = $this->createPlayer();
        $firstOrder = ($this->submitOrderHandler)(new SubmitOrder($player->id, $this->intents(['pottery']), $player->game->currentTurn));

        $secondOrder = ($this->submitOrderHandler)(new SubmitOrder($player->id, $this->intents(['democracy']), $player->game->currentTurn));

        self::assertSame($firstOrder->id->toRfc4122(), $secondOrder->id->toRfc4122());
        self::assertSame(['democracy'], $secondOrder->keys());
        self::assertSame(1, $this->orderRepository->count([
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

        self::assertSame(OrderStatus::Validated, $order->status);
        self::assertEquals([new OrderLine('democracy', 200)], $order->lines);
        self::assertSame(200, $order->total);
        self::assertContains('democracy', $player->advances);
    }

    #[Test]
    public function submitWithLibraryDiscountsTheOtherLineAndPersistsThePromotionPayload(): void
    {
        $player = $this->createPlayer();

        $order = ($this->submitOrderHandler)(new SubmitOrder($player->id, $this->intents(['library', 'democracy']), $player->game->currentTurn));

        $democracyLine = $order->lines()[1];
        self::assertSame('democracy', $democracyLine->key);
        self::assertSame(180, $democracyLine->netCost);
        self::assertInstanceOf(AppliedPromotion::class, $democracyLine->promotion);
        self::assertSame(PromotionType::Discount, $democracyLine->promotion->type);
        self::assertSame('library', $democracyLine->promotion->source);
        self::assertSame(40, $democracyLine->promotion->amount);
    }

    #[Test]
    public function validatingALibraryAndDemocracyOrderFreezesTheDiscountedTotal(): void
    {
        $player = $this->createPlayer();
        $order = ($this->submitOrderHandler)(new SubmitOrder($player->id, $this->intents(['library', 'democracy']), $player->game->currentTurn));

        $this->orderValidator->validate($order);

        self::assertSame(400, $order->total);
        $democracyLine = $order->lines()[1];
        self::assertSame(180, $democracyLine->netCost);
        self::assertInstanceOf(AppliedPromotion::class, $democracyLine->promotion);
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
