<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\GameSession;
use App\Entity\Order;
use App\Entity\Player;
use App\Repository\OrderRepository;
use App\Shop\Cart;
use App\Shop\CartRepository;
use App\Shop\Dto\OrderLine;
use App\Shop\OrderStatus;
use App\Shop\Promotion\AppliedPromotion;
use App\Shop\Promotion\PromotionType;
use App\Shop\Service\OrderValidator;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\BrowserKit\Cookie;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\UX\LiveComponent\Test\InteractsWithLiveComponents;
use Symfony\UX\LiveComponent\Test\TestLiveComponent;

/**
 * Acceptance tests for the per-player cashier (POS) flow: an operator opens a
 * player's order card for a given turn, builds a ticket of advances (either
 * via PlayerOrders' own add() LiveAction — including the nested ProductGrid+
 * Cart re-render it drives — or, for checkout preconditions, written straight
 * into App\Shop\CartRepository), checks it out directly
 * (App\Shop\CommandHandler\SellDirectHandler), or erases an already validated
 * order (App\Shop\CommandHandler\EraseOrdersHandler cascade), all from the
 * per-player PlayerOrders LiveComponent (organisms/playerOrders).
 *
 * Ticket line/gift/allocation rendering lives in App\Component\Cart, covered
 * by CartComponentTest (POS mode); the product grid lives in
 * App\Component\ProductGrid, covered by ProductGridComponentTest.
 *
 * Once a precondition ticket has been seeded on the shared client, the
 * PlayerOrders component under test is always freshly constructed afterwards
 * (never reused across an interleaved sequence) — TestLiveComponent::props()
 * reads props off the client's *last* response, so an already-mounted instance
 * whose turn came before an interleaved call on a different instance would send
 * a stale checksum. `posOpen`/`posTurn` are passed directly to the fresh
 * instance instead of driving them through an actual openPos() call.
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
        $crawler = $component->render()->crawler();

        // The pending order preloads a complete, single-item ticket, so "Confirm
        // purchase" — App\Component\Cart::hasIncompleteAllocations/isEmpty, read
        // from the nested Cart component itself — is enabled.
        $this->assertFalse($crawler->filter('[data-live-action-param="checkout"]')->getNode(0)->hasAttribute('disabled'));
        $this->assertTrue($component->component()->posOpen);
        $this->assertSame($game->currentTurn, $component->component()->posTurn);
    }

    #[Test]
    public function openPosOnAnEmptyTurnStartsWithAnEmptyTicket(): void
    {
        [$game, , $bob] = $this->createGameWithAliceAndBob();

        $component = $this->createPlayerOrders($bob);
        $component->call('openPos', ['turn' => $game->currentTurn]);
        $crawler = $component->render()->crawler();

        $this->assertTrue($crawler->filter('[data-live-action-param="checkout"]')->getNode(0)->hasAttribute('disabled'));
    }

    #[Test]
    public function addMarksTheProductInCartAndUpdatesTheTicketTotal(): void
    {
        [$game, , $bob] = $this->createGameWithAliceAndBob();

        $component = $this->createPlayerOrders($bob, posOpen: true, posTurn: $game->currentTurn);
        // A single call()+render() round trip: proves add() re-renders the nested
        // ProductGrid (in-cart marker) and Cart (total) together, not just PlayerOrders itself.
        $rendered = $component->call('add', ['key' => 'pottery'])->render()->toString();

        $this->assertStringContainsString('id="product-pottery"', $rendered);
        $this->assertStringContainsString('product-tile--in-cart', $rendered);
        $this->assertStringContainsString('Total: 60', $rendered);
    }

    #[Test]
    public function addingAnAlreadyOwnedAdvanceIsRefused(): void
    {
        [$game, $alice] = $this->createGameWithAliceAndBob();

        $component = $this->createPlayerOrders($alice, posOpen: true, posTurn: $game->currentTurn);
        $rendered = $component->call('add', ['key' => 'agriculture'])->render()->toString();

        $this->assertStringContainsString('already owned', $rendered);
    }

    #[Test]
    public function addingTheSameAdvanceTwiceIsDeduped(): void
    {
        [$game, , $bob] = $this->createGameWithAliceAndBob();

        $component = $this->createPlayerOrders($bob, posOpen: true, posTurn: $game->currentTurn);
        $component->call('add', ['key' => 'pottery']);
        $rendered = $component->call('add', ['key' => 'pottery'])->render()->toString();

        $this->assertSame(1, substr_count($rendered, 'class="shop__cart-line"'));
        $this->assertStringContainsString('Total: 60', $rendered);
    }

    #[Test]
    public function checkoutValidatesThePosTurnOrderAndOwnsTheAdvances(): void
    {
        [$game, , $bob] = $this->createGameWithAliceAndBob();
        $client = self::getContainer()->get('test.client');

        $this->posCartFor($client, $bob, Cart::fromKeys(['pottery', 'democracy']));

        $this->createPosCart($bob, $game->currentTurn, $client)->call('checkout');

        $order = $this->freshOrderRepository()->findOneByPlayerAndWindow($bob, $game->currentTurn);
        $this->assertInstanceOf(Order::class, $order);
        $this->assertSame(OrderStatus::Validated, $order->status);
        $this->assertSame(['pottery', 'democracy'], $this->reloadPlayer($bob)->advances);
    }

    #[Test]
    public function checkoutWithAChosenGiftValidatesTheOrderWithTheZeroCostGiftLine(): void
    {
        [$game, , $bob] = $this->createGameWithAliceAndBob();
        $client = self::getContainer()->get('test.client');

        $ticket = Cart::fromKeys(['anatomy']);
        $ticket->withGift('anatomy', 'astronavigation');
        $this->posCartFor($client, $bob, $ticket);

        $this->createPosCart($bob, $game->currentTurn, $client)->call('checkout');

        $order = $this->freshOrderRepository()->findOneByPlayerAndWindow($bob, $game->currentTurn);
        $this->assertInstanceOf(Order::class, $order);
        $this->assertSame(OrderStatus::Validated, $order->status);
        $this->assertSame(['anatomy', 'astronavigation'], $order->keys());

        $giftLine = $order->lines()[1];
        $this->assertSame('astronavigation', $giftLine->key);
        $this->assertSame(0, $giftLine->netCost);

        $this->assertSame(['anatomy', 'astronavigation'], $this->reloadPlayer($bob)->advances);
    }

    #[Test]
    public function checkoutIsDisabledWhileTheMonumentAllocationIsIncomplete(): void
    {
        [$game, , $bob] = $this->createGameWithAliceAndBob();
        $client = self::getContainer()->get('test.client');

        $this->posCartFor($client, $bob, Cart::fromKeys(['monument']));

        $crawler = $this->createPlayerOrders($bob, $client, posOpen: true, posTurn: $game->currentTurn)->render()->crawler();

        $this->assertTrue($crawler->filter('[data-live-action-param="checkout"]')->getNode(0)->hasAttribute('disabled'));
    }

    #[Test]
    public function checkoutIsDisabledWhileTheMonumentAllocationIsPartial(): void
    {
        [$game, , $bob] = $this->createGameWithAliceAndBob();
        $client = self::getContainer()->get('test.client');

        $ticket = Cart::fromKeys(['monument']);
        $ticket->withAllocation('monument', 'science', 5);
        $this->posCartFor($client, $bob, $ticket);

        $crawler = $this->createPlayerOrders($bob, $client, posOpen: true, posTurn: $game->currentTurn)->render()->crawler();

        $this->assertTrue($crawler->filter('[data-live-action-param="checkout"]')->getNode(0)->hasAttribute('disabled'));
    }

    #[Test]
    public function checkoutWithAPartialAllocationIsRejectedServerSideAndShowsAnError(): void
    {
        [$game, , $bob] = $this->createGameWithAliceAndBob();
        $client = self::getContainer()->get('test.client');

        $ticket = Cart::fromKeys(['monument']);
        $ticket->withAllocation('monument', 'science', 5);
        $this->posCartFor($client, $bob, $ticket);

        $rendered = $this->createPosCart($bob, $game->currentTurn, $client)->call('checkout')->render()->toString();

        $this->assertNotInstanceOf(\App\Entity\Order::class, $this->freshOrderRepository()->findOneByPlayerAndWindow($bob, $game->currentTurn));
        $this->assertStringContainsString('Allocation required for promotion on', $rendered);
        $this->assertStringContainsString('monument', $rendered);
    }

    #[Test]
    public function allocatingTheFullOptionPoolAtThePosEnablesCheckoutAndPersistsTheAllocation(): void
    {
        [$game, , $bob] = $this->createGameWithAliceAndBob();
        $client = self::getContainer()->get('test.client');

        $ticket = Cart::fromKeys(['monument']);
        $ticket->withAllocation('monument', 'craft', 10);
        $ticket->withAllocation('monument', 'science', 10);
        $this->posCartFor($client, $bob, $ticket);

        $crawler = $this->createPlayerOrders($bob, $client, posOpen: true, posTurn: $game->currentTurn)->render()->crawler();
        $this->assertFalse($crawler->filter('[data-live-action-param="checkout"]')->getNode(0)->hasAttribute('disabled'));

        $this->createPosCart($bob, $game->currentTurn, $client)->call('checkout');

        $order = $this->freshOrderRepository()->findOneByPlayerAndWindow($bob, $game->currentTurn);
        $this->assertInstanceOf(Order::class, $order);
        $this->assertSame(OrderStatus::Validated, $order->status);

        $line = $order->lines()[0];
        $this->assertInstanceOf(AppliedPromotion::class, $line->promotion);
        $this->assertSame(PromotionType::Option, $line->promotion->type);
        $this->assertSame(['craft' => 10, 'science' => 10], $line->promotion->allocation);
    }

    #[Test]
    public function reopeningThePosOnAKioskSubmittedMonumentOrderReloadsTheAllocationIntoTheTicket(): void
    {
        [$game, , $bob] = $this->createGameWithAliceAndBob();

        $order = new Order($bob, $game->currentTurn);
        $order->replaceLines([
            new OrderLine('monument', 180, new AppliedPromotion(PromotionType::Option, 'monument', allocation: ['craft' => 10, 'science' => 10])),
        ]);
        $this->entityManager->persist($order);
        $this->entityManager->flush();

        $component = $this->createPlayerOrders($bob);
        $component->call('openPos', ['turn' => $game->currentTurn]);

        $crawler = $component->render()->crawler();

        $this->assertStringContainsString('Remaining: 0', $crawler->filter('.allocation-picker')->text());
        // Category order is art/civic/craft/religion/science (App\Game\Category): only
        // craft and science were allocated, 10 each.
        $this->assertSame(['0', '0', '10', '0', '10'], $crawler->filter('.allocation-picker__value')->each(static fn ($node) => $node->text()));
    }

    #[Test]
    public function checkoutOnAPastTurnValidatesThatTurnsOrderNotTheCurrentOne(): void
    {
        [$game, , $bob] = $this->createGameWithAliceAndBob();
        $game->currentTurn = 3;
        $this->entityManager->flush();
        $client = self::getContainer()->get('test.client');

        $this->posCartFor($client, $bob, Cart::fromKeys(['pottery']));
        $this->createPosCart($bob, 1, $client)->call('checkout');

        $pastOrder = $this->freshOrderRepository()->findOneByPlayerAndWindow($bob, 1);
        $this->assertInstanceOf(Order::class, $pastOrder);
        $this->assertSame(OrderStatus::Validated, $pastOrder->status);

        $this->assertNotInstanceOf(Order::class, $this->freshOrderRepository()->findOneByPlayerAndWindow($bob, 3));
    }

    #[Test]
    public function checkoutOnAnAlreadyValidatedTurnShowsADomainExceptionMessage(): void
    {
        [$game, $alice] = $this->createGameWithAliceAndBob();
        $this->validateOrderFor($alice, ['democracy']);
        $client = self::getContainer()->get('test.client');

        $this->posCartFor($client, $alice, Cart::fromKeys(['pottery']));
        $rendered = $this->createPosCart($alice, $game->currentTurn, $client)->call('checkout')->render()->toString();

        $this->assertStringContainsString('already been validated', $rendered);
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

        $this->assertNotInstanceOf(Order::class, $this->freshOrderRepository()->findOneByPlayerAndWindow($alice, 1));
        $this->assertNotInstanceOf(Order::class, $this->freshOrderRepository()->findOneByPlayerAndWindow($alice, 2));

        $reloadedAlice = $this->reloadPlayer($alice);
        $this->assertNotContains('democracy', $reloadedAlice->advances);
        $this->assertNotContains('pottery', $reloadedAlice->advances);
        $this->assertContains('agriculture', $reloadedAlice->advances);
    }

    #[Test]
    public function cardsRunFromCurrentTurnDownToOneAndAKioskPendingOrderFillsItsTurnCard(): void
    {
        [$game, , $bob] = $this->createGameWithAliceAndBob();
        $game->currentTurn = 3;
        $this->entityManager->flush();
        $this->createPendingOrderFor($bob, ['pottery']);

        $cards = $this->createPlayerOrders($bob)->render()->crawler()->filter('article');

        $this->assertCount(3, $cards);
        $this->assertStringContainsString('Turn 3', $cards->eq(0)->text());
        $this->assertSame('pending', $cards->eq(0)->attr('data-status'));
        $this->assertStringContainsString('Turn 2', $cards->eq(1)->text());
        $this->assertStringContainsString('Turn 1', $cards->eq(2)->text());
    }

    #[Test]
    public function aMissingCurrentTurnRendersAMissingStatusCardWithABuyButton(): void
    {
        [, , $bob] = $this->createGameWithAliceAndBob();

        $crawler = $this->createPlayerOrders($bob)->render()->crawler();
        $card = $crawler->filter('article')->first();

        $this->assertSame('missing', $card->attr('data-status'));
        $this->assertStringContainsString('Empty', $card->text());
        $this->assertStringContainsString('Total: 0', $card->text());
        $this->assertStringContainsString('VP: 0', $card->text());
        $this->assertSame('Buy', trim($card->filter('button')->first()->text()));
    }

    #[Test]
    public function aPendingOrderCardShowsRecomputedNetCostsAndAVerifyButton(): void
    {
        [, , $bob] = $this->createGameWithAliceAndBob();
        $this->createPendingOrderFor($bob, ['pottery']);

        $crawler = $this->createPlayerOrders($bob)->render()->crawler();
        $card = $crawler->filter('article')->first();

        $this->assertSame('pending', $card->attr('data-status'));
        $this->assertStringContainsString('Pottery', $card->text());
        $this->assertStringContainsString('Total: 60', $card->text());
        $this->assertStringContainsString('VP: 1', $card->text());
        $this->assertSame('Verify', trim($card->filter('button')->first()->text()));
    }

    #[Test]
    public function pastTurnsWithNoOrderStayEmptyWhileTheCurrentTurnIsPending(): void
    {
        [$game, , $bob] = $this->createGameWithAliceAndBob();
        $game->currentTurn = 3;
        $this->entityManager->flush();
        $this->createPendingOrderFor($bob, ['pottery']);

        $cards = $this->createPlayerOrders($bob)->render()->crawler()->filter('article');

        $this->assertSame('pending', $cards->eq(0)->attr('data-status'));

        $this->assertSame('empty', $cards->eq(1)->attr('data-status'));
        $this->assertSame('Edit', trim($cards->eq(1)->filter('button')->first()->text()));

        $this->assertSame('empty', $cards->eq(2)->attr('data-status'));
        $this->assertSame('Edit', trim($cards->eq(2)->filter('button')->first()->text()));
    }

    #[Test]
    public function aValidatedOrderCardShowsFrozenNetCostsAndAnEmptyButton(): void
    {
        [, , $bob] = $this->createGameWithAliceAndBob();
        $this->validateOrderFor($bob, ['democracy', 'pottery']);

        $crawler = $this->createPlayerOrders($bob)->render()->crawler();
        $card = $crawler->filter('article')->first();

        $this->assertSame('validated', $card->attr('data-status'));
        $this->assertStringContainsString('Democracy', $card->text());
        $this->assertStringContainsString('Pottery', $card->text());
        $this->assertStringContainsString('Total: 280', $card->text());
        // democracy: 6 points, pottery: 1 point (config/game/advances.yaml).
        $this->assertStringContainsString('VP: 7', $card->text());
        $this->assertSame('Empty', trim($card->filter('button')->first()->text()));
    }

    #[Test]
    public function eraseConfirmForTheCurrentTurnHasNoCascadeMention(): void
    {
        [$game, $alice] = $this->createGameWithAliceAndBob();
        $game->currentTurn = 1;
        $this->entityManager->flush();
        $this->validateOrderFor($alice, ['democracy']);

        $rendered = $this->createPlayerOrders($alice)->render()->toString();

        $this->assertStringContainsString('Empty turn 1?', $rendered);
        $this->assertStringNotContainsString('also empty turn', $rendered);
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

    private function createPlayerOrders(Player $player, ?KernelBrowser $client = null, bool $posOpen = false, int $posTurn = 0): TestLiveComponent
    {
        return $this->createLiveComponent('PlayerOrders', [
            'player' => $player,
            'ordersStamp' => '',
            'posOpen' => $posOpen,
            'posTurn' => $posTurn,
        ], $client);
    }

    /**
     * Checkout now lives on the Cart LiveComponent (see App\Component\Cart::checkout)
     * — PlayerOrders only hosts the dialog. $client must be shared with the
     * PlayerOrders instance that opened the POS (openPos()) so this Cart reads
     * the same session-backed ticket.
     */
    private function createPosCart(Player $player, int $turn, KernelBrowser $client): TestLiveComponent
    {
        return $this->createLiveComponent('Cart', [
            'player' => $player,
            'storageKey' => 'pos.'.$player->id->toRfc4122(),
            'directSale' => true,
            'window' => $turn,
        ], $client);
    }

    /**
     * Writes straight into the session-backed App\Shop\CartRepository (Cart has
     * no add() action of its own any more) and points $client's cookie jar at
     * that session, so the PlayerOrders component under test — driven by the
     * same $client — reads the same ticket back. 'test.client' is registered
     * share(false) (Symfony\Bundle\FrameworkBundle\Resources\config\test.php),
     * so $client must be the exact instance later passed to createPlayerOrders().
     */
    private function posCartFor(KernelBrowser $client, Player $player, Cart $cart): void
    {
        $session = self::getContainer()->get('session.factory')->createSession();
        $request = new Request();
        $request->setSession($session);
        $requestStack = self::getContainer()->get(RequestStack::class);
        $requestStack->push($request);
        self::getContainer()->get(CartRepository::class)->save('pos.'.$player->id->toRfc4122(), $cart);
        $requestStack->pop();
        $session->save();

        $client->getCookieJar()->set(new Cookie($session->getName(), $session->getId()));
    }

    /** @param list<string> $slugs */
    private function createPendingOrderFor(Player $player, array $slugs): Order
    {
        $order = new Order($player, $player->game->currentTurn);
        $order->replaceLines(array_map(static fn (string $slug): OrderLine => new OrderLine($slug, 0), $slugs));
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
        $this->assertInstanceOf(Player::class, $reloaded);

        return $reloaded;
    }
}
