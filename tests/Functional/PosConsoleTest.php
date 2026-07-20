<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\GameSession;
use App\Entity\Order;
use App\Entity\Player;
use App\Repository\OrderRepository;
use App\Shop\OrderStatus;
use App\Shop\Service\OrderValidator;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\UX\LiveComponent\Test\InteractsWithLiveComponents;

/**
 * Acceptance tests for the per-player cashier (POS) flow: an operator opens a
 * player's order card for a given turn, builds a ticket of advances and
 * checks it out directly (App\Shop\Service\DirectSale), or erases an already
 * validated order (App\Shop\Service\OrderEraser cascade), all from the
 * per-player PlayerOrders LiveComponent (organisms/playerOrders).
 */
final class PosConsoleTest extends WebTestCase
{
    use InteractsWithLiveComponents;

    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        self::bootKernel();

        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
    }

    #[Test]
    public function openPosPreloadsThePendingOrdersTicket(): void
    {
        [$game, , $bob] = $this->createGameWithAliceAndBob();
        $this->createPendingOrderFor($bob, ['pottery']);

        $component = $this->createPlayerOrders($bob);
        $component->call('openPos', ['turn' => $game->currentTurn]);

        self::assertSame(['pottery'], $component->component()->ticket);
        self::assertTrue($component->component()->posOpen);
        self::assertSame($game->currentTurn, $component->component()->posTurn);
    }

    #[Test]
    public function openPosOnAnEmptyTurnStartsWithAnEmptyTicket(): void
    {
        [$game, , $bob] = $this->createGameWithAliceAndBob();

        $component = $this->createPlayerOrders($bob);
        $component->call('openPos', ['turn' => $game->currentTurn]);

        self::assertSame([], $component->component()->ticket);
    }

    #[Test]
    public function addingAnAlreadyOwnedAdvanceToTheTicketIsRefused(): void
    {
        [$game, $alice] = $this->createGameWithAliceAndBob();

        $component = $this->createPlayerOrders($alice);
        $component->call('openPos', ['turn' => $game->currentTurn]);
        $rendered = $component->call('addToTicket', ['key' => 'agriculture'])->render()->toString();

        self::assertSame([], $component->component()->ticket);
        self::assertStringContainsString('already owned', $rendered);
    }

    #[Test]
    public function addingTheSameAdvanceTwiceIsDeduped(): void
    {
        [$game, , $bob] = $this->createGameWithAliceAndBob();

        $component = $this->createPlayerOrders($bob);
        $component->call('openPos', ['turn' => $game->currentTurn]);
        $component->call('addToTicket', ['key' => 'pottery']);
        $component->call('addToTicket', ['key' => 'pottery']);

        self::assertSame(['pottery'], $component->component()->ticket);
    }

    #[Test]
    public function removeFromTicketRemovesTheGivenKey(): void
    {
        [$game, , $bob] = $this->createGameWithAliceAndBob();

        $component = $this->createPlayerOrders($bob);
        $component->call('openPos', ['turn' => $game->currentTurn]);
        $component->call('addToTicket', ['key' => 'pottery']);
        $component->call('addToTicket', ['key' => 'democracy']);
        $component->call('removeFromTicket', ['key' => 'pottery']);

        self::assertSame(['democracy'], $component->component()->ticket);
    }

    #[Test]
    public function checkoutValidatesThePosTurnOrderAndOwnsTheAdvances(): void
    {
        [$game, , $bob] = $this->createGameWithAliceAndBob();

        $component = $this->createPlayerOrders($bob);
        $component->call('openPos', ['turn' => $game->currentTurn]);
        $component->call('addToTicket', ['key' => 'pottery']);
        $component->call('addToTicket', ['key' => 'democracy']);
        $component->call('checkout');

        $order = $this->freshOrderRepository()->findOneByPlayerAndTurn($bob, $game->currentTurn);
        self::assertInstanceOf(Order::class, $order);
        self::assertSame(OrderStatus::Validated, $order->status);
        self::assertSame(['pottery', 'democracy'], $this->reloadPlayer($bob)->advances);
        self::assertSame([], $component->component()->ticket);
    }

    #[Test]
    public function checkoutOnAPastTurnValidatesThatTurnsOrderNotTheCurrentOne(): void
    {
        [$game, , $bob] = $this->createGameWithAliceAndBob();
        $game->currentTurn = 3;
        $this->entityManager->flush();

        $component = $this->createPlayerOrders($bob);
        $component->call('openPos', ['turn' => 1]);
        $component->call('addToTicket', ['key' => 'pottery']);
        $component->call('checkout');

        $pastOrder = $this->freshOrderRepository()->findOneByPlayerAndTurn($bob, 1);
        self::assertInstanceOf(Order::class, $pastOrder);
        self::assertSame(OrderStatus::Validated, $pastOrder->status);

        self::assertNull($this->freshOrderRepository()->findOneByPlayerAndTurn($bob, 3));
    }

    #[Test]
    public function checkoutOnAnAlreadyValidatedTurnShowsADomainExceptionMessage(): void
    {
        [$game, $alice] = $this->createGameWithAliceAndBob();
        $this->validateOrderFor($alice, ['democracy']);

        $component = $this->createPlayerOrders($alice);
        $component->call('openPos', ['turn' => $game->currentTurn]);
        $component->call('addToTicket', ['key' => 'pottery']);
        $rendered = $component->call('checkout')->render()->toString();

        self::assertStringContainsString('already been validated', $rendered);
    }

    #[Test]
    public function posProductsRenderAsButtonsWithNameAndNetCostAndBiCategoryAdvancesCarryTwoStripeColors(): void
    {
        [$game, , $bob] = $this->createGameWithAliceAndBob();

        $component = $this->createPlayerOrders($bob);
        $component->call('openPos', ['turn' => $game->currentTurn]);
        $crawler = $component->render()->crawler();

        $pottery = $crawler->filter('#product-pottery');
        self::assertSame('button', $pottery->nodeName());
        self::assertStringContainsString('Pottery', $pottery->text());
        self::assertStringContainsString('60', $pottery->text());

        // Mysticism spans two categories (religion + art), so it must carry two distinct
        // --cat-1/--cat-2 custom properties feeding the tile's striped background.
        $mysticism = $crawler->filter('#product-mysticism');
        self::assertSame('button', $mysticism->nodeName());
        self::assertStringContainsString('Mysticism', $mysticism->text());
        self::assertStringContainsString('50', $mysticism->text());
        self::assertSame(
            '--cat-1: var(--advance-religion); --cat-2: var(--advance-art)',
            $mysticism->attr('style'),
        );
    }

    #[Test]
    public function eraseOrderCascadesRemovingLaterTurnsAndDisowningAdvances(): void
    {
        [$game, $alice] = $this->createGameWithAliceAndBob();
        $game->currentTurn = 1;
        $this->entityManager->flush();
        $this->validateOrderFor($alice, ['democracy']);

        $game->currentTurn = 2;
        $this->entityManager->flush();
        $this->validateOrderFor($alice, ['pottery']);

        $component = $this->createPlayerOrders($alice);
        $component->call('eraseOrder', ['turn' => 1]);

        self::assertNull($this->freshOrderRepository()->findOneByPlayerAndTurn($alice, 1));
        self::assertNull($this->freshOrderRepository()->findOneByPlayerAndTurn($alice, 2));

        $reloadedAlice = $this->reloadPlayer($alice);
        self::assertNotContains('democracy', $reloadedAlice->advances);
        self::assertNotContains('pottery', $reloadedAlice->advances);
        self::assertContains('agriculture', $reloadedAlice->advances);
    }

    #[Test]
    public function cardsRunFromCurrentTurnDownToOneAndAKioskPendingOrderFillsItsTurnCard(): void
    {
        [$game, , $bob] = $this->createGameWithAliceAndBob();
        $game->currentTurn = 3;
        $this->entityManager->flush();
        // Submitted from the player's kiosk: must fill the turn-3 card, not add a duplicate.
        $this->createPendingOrderFor($bob, ['pottery']);

        $cards = $this->createPlayerOrders($bob)->render()->crawler()->filter('article');

        self::assertCount(3, $cards);
        self::assertStringContainsString('Turn 3', $cards->eq(0)->text());
        self::assertSame('pending', $cards->eq(0)->attr('data-status'));
        self::assertStringContainsString('Turn 2', $cards->eq(1)->text());
        self::assertStringContainsString('Turn 1', $cards->eq(2)->text());
    }

    #[Test]
    public function anEmptyTurnRendersAnEmptyStatusCardWithAnEditButton(): void
    {
        [, , $bob] = $this->createGameWithAliceAndBob();

        $crawler = $this->createPlayerOrders($bob)->render()->crawler();
        $card = $crawler->filter('article')->first();

        self::assertSame('empty', $card->attr('data-status'));
        self::assertStringContainsString('Empty', $card->text());
        self::assertStringContainsString('Total: 0', $card->text());
        self::assertStringContainsString('VP: 0', $card->text());
        self::assertSame('Edit', trim($card->filter('button')->first()->text()));
    }

    #[Test]
    public function aPendingOrderCardShowsRecomputedNetCostsAndAnEditButton(): void
    {
        [, , $bob] = $this->createGameWithAliceAndBob();
        $this->createPendingOrderFor($bob, ['pottery']);

        $crawler = $this->createPlayerOrders($bob)->render()->crawler();
        $card = $crawler->filter('article')->first();

        self::assertSame('pending', $card->attr('data-status'));
        self::assertStringContainsString('Pottery', $card->text());
        // pottery costs 60, Bob owns nothing granting a credit.
        self::assertStringContainsString('Total: 60', $card->text());
        self::assertStringContainsString('VP: 1', $card->text());
        self::assertSame('Edit', trim($card->filter('button')->first()->text()));
    }

    #[Test]
    public function aValidatedOrderCardShowsFrozenNetCostsAndAnEmptyButton(): void
    {
        [, , $bob] = $this->createGameWithAliceAndBob();
        $this->validateOrderFor($bob, ['democracy', 'pottery']);

        $crawler = $this->createPlayerOrders($bob)->render()->crawler();
        $card = $crawler->filter('article')->first();

        self::assertSame('validated', $card->attr('data-status'));
        self::assertStringContainsString('Democracy', $card->text());
        self::assertStringContainsString('Pottery', $card->text());
        self::assertStringContainsString('Total: 280', $card->text());
        // democracy: 6 points, pottery: 1 point (config/game/advances.yaml).
        self::assertStringContainsString('VP: 7', $card->text());
        self::assertSame('Empty', trim($card->filter('button')->first()->text()));
    }

    #[Test]
    public function eraseConfirmForTheCurrentTurnHasNoCascadeMention(): void
    {
        [$game, $alice] = $this->createGameWithAliceAndBob();
        $game->currentTurn = 1;
        $this->entityManager->flush();
        $this->validateOrderFor($alice, ['democracy']);

        $rendered = $this->createPlayerOrders($alice)->render()->toString();

        self::assertStringContainsString('Empty turn 1?', $rendered);
        self::assertStringNotContainsString('also empty turn', $rendered);
    }

    /** @return array{GameSession, Player, Player} */
    private function createGameWithAliceAndBob(): array
    {
        $game = new GameSession();
        $alice = new Player($game, 'Alice');
        $alice->ownAdvances(['agriculture']);
        $bob = new Player($game, 'Bob');

        $this->entityManager->persist($game);
        $this->entityManager->persist($alice);
        $this->entityManager->persist($bob);
        $this->entityManager->flush();

        return [$game, $alice, $bob];
    }

    private function createPlayerOrders(Player $player): object
    {
        return $this->createLiveComponent('PlayerOrders', [
            'player' => $player,
            'ordersStamp' => '',
        ]);
    }

    /** @param list<string> $slugs */
    private function createPendingOrderFor(Player $player, array $slugs): Order
    {
        $order = new Order($player, $player->game->currentTurn);
        $order->replaceLines($slugs);
        $this->entityManager->persist($order);
        $this->entityManager->flush();

        return $order;
    }

    /** @param list<string> $slugs */
    private function validateOrderFor(Player $player, array $slugs): Order
    {
        $order = $this->createPendingOrderFor($player, $slugs);

        self::getContainer()->get(OrderValidator::class)->validate($order);

        return $order;
    }

    private function freshOrderRepository(): OrderRepository
    {
        return self::getContainer()->get(OrderRepository::class);
    }

    private function reloadPlayer(Player $player): Player
    {
        $reloaded = self::getContainer()->get(EntityManagerInterface::class)->find(Player::class, $player->id);
        self::assertInstanceOf(Player::class, $reloaded);

        return $reloaded;
    }
}
