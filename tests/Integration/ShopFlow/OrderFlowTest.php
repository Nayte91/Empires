<?php

declare(strict_types=1);

namespace App\Tests\Integration\ShopFlow;

use App\Engine\Shop\AdvanceFulfillment;
use App\Infrastructure\Repository\OrderRepository;
use App\State\CreditEntry;
use App\Tests\Support\Fixture\GameBuilder;
use App\Tests\Support\Fixture\PlayerBuilder;
use App\Tests\Support\GameFixtureTrait;
use App\Tests\Support\ShopFixtureTrait;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Userforged\ShopEngine\Command\SubmitOrder;
use Userforged\ShopEngine\CommandHandler\SubmitOrderHandler;
use Userforged\ShopEngine\Dto\OrderLine;
use Userforged\ShopEngine\Exception\CartException;
use Userforged\ShopEngine\Exception\EligibilityException;
use Userforged\ShopEngine\Exception\OrderException;
use Userforged\ShopEngine\OrderStatus;
use Userforged\ShopEngine\Promotion\AppliedPromotion;
use Userforged\ShopEngine\Promotion\PromotionType;
use Userforged\ShopEngine\Service\OrderValidator;

final class OrderFlowTest extends WebTestCase
{
    use GameFixtureTrait;
    use ShopFixtureTrait;

    private OrderRepository $orderRepository;
    private SubmitOrderHandler $submitOrderHandler;
    private OrderValidator $orderValidator;
    private AdvanceFulfillment $fulfillment;

    protected function setUp(): void
    {
        $this->initEntityManager();

        $this->orderRepository = self::getContainer()->get(OrderRepository::class);
        $this->submitOrderHandler = self::getContainer()->get(SubmitOrderHandler::class);
        $this->orderValidator = self::getContainer()->get(OrderValidator::class);
        $this->fulfillment = self::getContainer()->get(AdvanceFulfillment::class);
    }

    #[Test]
    public function submitWithEmptyCartThrowsCartException(): void
    {
        $player = PlayerBuilder::named('Alice')->persist($this->entityManager);

        $this->expectException(CartException::class);

        ($this->submitOrderHandler)(new SubmitOrder($player->id, $this->intents([]), $player->game->currentTurn));
    }

    #[Test]
    public function submitCreatesPendingOrderWithSlugs(): void
    {
        $player = PlayerBuilder::named('Alice')->persist($this->entityManager);

        $order = ($this->submitOrderHandler)(new SubmitOrder($player->id, $this->intents(['pottery', 'agriculture']), $player->game->currentTurn));

        $this->assertSame(OrderStatus::Pending, $order->status);
        $this->assertSame(['pottery', 'agriculture'], $order->keys());
    }

    #[Test]
    public function resubmitReplacesLinesOnSameOrderAndKeepsUniqueRow(): void
    {
        $player = PlayerBuilder::named('Alice')->persist($this->entityManager);
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
    public function validatingAnOrderLateStampsItsCreditsWithTheTurnItWasBoughtNotTheTurnItWasValidated(): void
    {
        $player = PlayerBuilder::named('JM')->in(GameBuilder::create()->withCurrentTurn(8)->build())->persist($this->entityManager);
        $order = ($this->submitOrderHandler)(new SubmitOrder($player->id, $this->intents(['pottery']), 8));
        $player->game->currentTurn = 11;
        $this->entityManager->flush();

        $this->orderValidator->validate($order);

        $this->assertSame([8, 8, 8], array_map(static fn (CreditEntry $entry): int => $entry->turn, $player->creditLedger));
    }

    #[Test]
    public function validateFreezesLinesAndOwnsAdvances(): void
    {
        $player = PlayerBuilder::named('Alice')->persist($this->entityManager);
        $this->fulfillment->grant($player->id, ['agriculture'], $player->game->currentTurn);
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
        $player = PlayerBuilder::named('Alice')->persist($this->entityManager);

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
        $player = PlayerBuilder::named('Alice')->persist($this->entityManager);
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
        $player = PlayerBuilder::named('Alice')->persist($this->entityManager);
        $order = ($this->submitOrderHandler)(new SubmitOrder($player->id, $this->intents(['pottery']), $player->game->currentTurn));
        $this->orderValidator->validate($order);

        $this->expectException(OrderException::class);

        ($this->submitOrderHandler)(new SubmitOrder($player->id, $this->intents(['democracy']), $player->game->currentTurn));
    }

    #[Test]
    public function submitWithAlreadyOwnedAdvanceInCartThrowsEligibilityException(): void
    {
        $player = PlayerBuilder::named('Alice')->persist($this->entityManager);
        $player->ownAdvances(['pottery']);
        $this->entityManager->flush();

        $this->expectException(EligibilityException::class);
        $this->expectExceptionMessageMatches('/pottery/');

        ($this->submitOrderHandler)(new SubmitOrder($player->id, $this->intents(['pottery']), $player->game->currentTurn));
    }
}
