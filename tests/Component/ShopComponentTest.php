<?php

declare(strict_types=1);

namespace App\Tests\Component;

use App\State\Order;
use App\State\Player;
use App\Infrastructure\Repository\OrderRepository;
use App\Tests\Support\Fixture\PlayerBuilder;
use App\Tests\Support\GameFixtureTrait;
use Userforged\ShopEngine\Cart;
use Userforged\ShopEngine\CartStorageInterface;
use Userforged\ShopEngine\Dto\OrderLine;
use Userforged\ShopEngine\OrderStatus;
use Userforged\ShopEngine\Promotion\AppliedPromotion;
use Userforged\ShopEngine\Promotion\PromotionType;
use Userforged\ShopEngine\Service\OrderValidator;
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
 * Cart line/gift/allocation rendering lives in App\Presentation\Component\Cart, covered by
 * CartComponentTest; the catalog lives in App\Presentation\Component\Catalog,
 * covered by CatalogComponentTest. This file keeps what Shop owns: its
 * own add() LiveAction (including the nested Catalog+Cart re-render it
 * drives), order submission, pending/validated/rejected order views, and the
 * Mercure/route wiring.
 *
 * The guard sequence add() runs — already-owned refusal, then de-duplication — now lives in
 * App\Presentation\Shop\CartItemAdder, shared with App\Presentation\Component\PlayerOrders, so it is covered once and
 * here. What stays per host is what each host wraps around it: Shop's turn lock below, and the
 * POS dialog that renders PlayerOrders' refusal, in PosConsoleTest.
 */
final class ShopComponentTest extends WebTestCase
{
    use GameFixtureTrait;
    use InteractsWithLiveComponents;

    #[Test]
    public function discountsAreRenderedInTheKioskWithTheOwnedCategoryColors(): void
    {
        $player = PlayerBuilder::named('Alice')->persist($this->entityManager);
        $player->ownAdvances(['agriculture']);
        $this->entityManager->flush();

        $rendered = $this->createLiveComponent('Shop', ['player' => $player])->render()->toString();

        // Agriculture provides credits for craft and science categories
        $this->assertStringContainsString('data-advance-category="craft"', $rendered);
        $this->assertStringContainsString('data-advance-category="science"', $rendered);
    }

    #[Test]
    public function addMarksTheProductInCartAndUpdatesTheCartTotal(): void
    {
        $player = PlayerBuilder::named('Alice')->persist($this->entityManager);

        $component = $this->createLiveComponent('Shop', ['player' => $player]);
        // A single call()+render() round trip: proves add() re-renders the nested
        // Catalog (in-cart marker) and Cart (total) together, not just Shop itself.
        $rendered = $component->call('add', ['key' => 'pottery'])->render()->toString();

        $this->assertStringContainsString('id="product-pottery"', $rendered);
        $this->assertStringContainsString('data-in-cart', $rendered);
        $this->assertStringContainsString('Total: 60', $rendered);
    }

    #[Test]
    public function addIsANoOpAndSetsAnErrorWhenTheCartIsLocked(): void
    {
        $player = PlayerBuilder::named('Alice')->persist($this->entityManager);
        $order = $this->createPendingOrder($player, 'pottery');
        $order->freeze([new OrderLine('pottery', 60)], 60);
        $order->setMarking(OrderStatus::Validated->value);
        $this->entityManager->flush();

        $component = $this->createLiveComponent('Shop', ['player' => $player]);
        $rendered = $component->call('add', ['key' => 'democracy'])->render()->toString();

        $this->assertStringContainsString('An order has already been validated for this turn.', $rendered);
    }

    #[Test]
    public function addingAnAlreadyOwnedAdvanceIsRefused(): void
    {
        $player = PlayerBuilder::named('Alice')->persist($this->entityManager);
        $player->ownAdvances(['agriculture']);
        $this->entityManager->flush();

        $component = $this->createLiveComponent('Shop', ['player' => $player]);
        $rendered = $component->call('add', ['key' => 'agriculture'])->render()->toString();

        $this->assertStringContainsString('already owned', $rendered);
        $this->assertStringContainsString('Cart is empty.', $rendered);
    }

    #[Test]
    public function addingTheSameAdvanceTwiceIsDeduped(): void
    {
        $player = PlayerBuilder::named('Alice')->persist($this->entityManager);

        $component = $this->createLiveComponent('Shop', ['player' => $player]);
        $component->call('add', ['key' => 'pottery']);
        $rendered = $component->call('add', ['key' => 'pottery'])->render()->toString();

        $this->assertSame(1, substr_count($rendered, 'class="shop__cart-line"'));
        $this->assertStringContainsString('Total: 60', $rendered);
    }

    #[Test]
    public function submitOrderCreatesAPendingOrderAndEmptiesTheCart(): void
    {
        $player = PlayerBuilder::named('Alice')->persist($this->entityManager);
        $client = self::getContainer()->get('test.client');
        $this->cartFor($client, $player, Cart::fromKeys(['pottery']));

        $this->createCart($player, $client)->call('checkout');

        $order = $this->freshOrderRepository()->findOneByPlayerAndWindow($player, $player->game->currentTurn);
        $this->assertInstanceOf(Order::class, $order);
        $this->assertSame(['pottery'], $order->keys());

        $rendered = $this->createLiveComponent('Shop', ['player' => $player])->render()->toString();
        $this->assertStringNotContainsString('shop__cart-lines', $rendered);
        $this->assertStringContainsString('Order pending for this turn.', $rendered);
        $this->assertStringContainsString('Pottery', $rendered);
        $this->assertStringContainsString('Modify', $rendered);
    }

    #[Test]
    public function pendingOrderHidesTheCartAndShowsTheOrderBlockWithModify(): void
    {
        $player = PlayerBuilder::named('Alice')->persist($this->entityManager);
        $this->createPendingOrder($player, 'pottery');

        $rendered = $this->createLiveComponent('Shop', ['player' => $player])->render()->toString();

        $this->assertStringNotContainsString('shop__cart-lines', $rendered);
        $this->assertStringContainsString('Order pending for this turn.', $rendered);
        $this->assertStringContainsString('Pottery', $rendered);
        $this->assertStringContainsString('Modify', $rendered);
    }

    #[Test]
    public function editPendingOrderReloadsItsLinesIntoTheCartAndHidesTheOrderBlock(): void
    {
        $player = PlayerBuilder::named('Alice')->persist($this->entityManager);
        $order = $this->createPendingOrder($player, 'pottery', 'agriculture');

        $component = $this->createLiveComponent('Shop', ['player' => $player]);
        $component->call('editPendingOrder');

        $rendered = $component->render()->toString();

        $this->assertStringContainsString('shop__cart-lines', $rendered);
        $this->assertStringContainsString('data-in-cart', $rendered);
        $this->assertSame(2, substr_count($rendered, 'data-live-action-param="remove"'));
        $this->assertStringNotContainsString('Modify', $rendered);
        $this->assertNotNull($order->id);
    }

    #[Test]
    public function aValidatedOrderLocksTheKioskForTheTurn(): void
    {
        $player = PlayerBuilder::named('Alice')->persist($this->entityManager);
        $order = $this->createPendingOrder($player, 'pottery');
        $order->freeze([new OrderLine('pottery', 60)], 60);
        $order->setMarking(OrderStatus::Validated->value);
        $this->entityManager->flush();

        $component = $this->createLiveComponent('Shop', ['player' => $player]);
        $this->assertTrue($this->getShopComponent($component)->isLockedForTurn());

        $rendered = $component->render()->toString();
        $this->assertStringNotContainsString('shop__cart-lines', $rendered);
        $this->assertStringNotContainsString('Modify', $rendered);
        $this->assertStringContainsString('Order validated for this turn.', $rendered);
        $this->assertStringContainsString('Pottery', $rendered);
    }

    /**
     * Regression pin: an operator can validate a POS sale for a turn while the
     * player's own shop cart (a different storage key) still holds unrelated
     * items — e.g. added before the operator closed the sale directly. The
     * cart block stays visible (isCartVisible: order exists but cart is not
     * empty), so it must render with checkout disabled rather than let the
     * player click into a server-side "already validated" error.
     */
    #[Test]
    public function aValidatedOrderWithLeftoverCartItemsKeepsSubmitDisabled(): void
    {
        $player = PlayerBuilder::named('Alice')->persist($this->entityManager);
        $order = $this->createPendingOrder($player, 'pottery');
        $order->freeze([new OrderLine('pottery', 60)], 60);
        $order->setMarking(OrderStatus::Validated->value);
        $this->entityManager->flush();

        $client = self::getContainer()->get('test.client');
        $this->cartFor($client, $player, Cart::fromKeys(['democracy']));

        $crawler = $this->createLiveComponent('Shop', ['player' => $player], $client)->render()->crawler();

        $this->assertStringContainsString('Democracy', $crawler->filter('.shop__cart-lines')->text());
        $this->assertTrue($crawler->filter('[data-live-action-param="checkout"]')->getNode(0)->hasAttribute('disabled'));
    }

    #[Test]
    public function validatedOrderShowsFrozenPricesRatherThanRecomputedOnes(): void
    {
        $player = PlayerBuilder::named('Alice')->persist($this->entityManager);
        $order = $this->createPendingOrder($player, 'pottery');
        $order->freeze([new OrderLine('pottery', 999)], 999);
        $order->setMarking(OrderStatus::Validated->value);
        $this->entityManager->flush();

        $rendered = $this->createLiveComponent('Shop', ['player' => $player])->render()->toString();

        $this->assertStringContainsString('999', $rendered);
        $this->assertStringNotContainsString('Modify', $rendered);
        $this->assertStringNotContainsString('shop__cart-lines', $rendered);
    }

    #[Test]
    public function aRejectedOrderReopensForEditingAndResubmittingReturnsItToPending(): void
    {
        $player = PlayerBuilder::named('Alice')->persist($this->entityManager);
        $order = $this->createPendingOrder($player, 'pottery');
        $order->setMarking(OrderStatus::Rejected->value);
        $this->entityManager->flush();

        $client = self::getContainer()->get('test.client');
        $component = $this->createLiveComponent('Shop', ['player' => $player], $client);
        $rendered = $component->render()->toString();

        $this->assertStringContainsString('revise and resubmit', $rendered);

        $component->call('editPendingOrder');
        $editedRendered = $component->render()->toString();
        $this->assertStringContainsString('shop__cart-lines', $editedRendered);
        $this->assertStringContainsString('data-in-cart', $editedRendered);

        $this->createCart($player, $client)->call('checkout');

        $reloadedOrder = $this->freshOrderRepository()->findOneByPlayerAndWindow($player, $player->game->currentTurn);
        $this->assertInstanceOf(Order::class, $reloadedOrder);
        $this->assertSame(OrderStatus::Pending, $reloadedOrder->status);
    }

    #[Test]
    public function mercureRefreshFiltersToGameUpdatedAndOrderUpdated(): void
    {
        $player = PlayerBuilder::named('Alice')->persist($this->entityManager);

        $rendered = $this->createLiveComponent('Shop', ['player' => $player])->render()->toString();

        $this->assertStringContainsString('data-mercure-refresh-events-value', $rendered);
        $this->assertStringContainsString('game-updated', $rendered);
        $this->assertStringContainsString('order-updated', $rendered);
    }

    #[Test]
    public function getPlayerShopReturnsTwoHundredWithLiveAndMercureWiring(): void
    {
        $player = PlayerBuilder::named('Alice')->persist($this->entityManager);

        // setUp() already booted the kernel for the live-component tests above,
        // so createClient() (which forbids a pre-booted kernel) is not an option:
        // fetch the test client from the container and register it manually for
        // the assertResponse*/assertSelector* helpers, exactly what createClient()
        // does under the hood.
        $client = self::getClient(self::getContainer()->get('test.client'));
        $client->request('GET', '/'.$player->game->slug.'/player/'.$player->slug.'/shop');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('[data-controller~="live"]');

        $html = (string) $client->getResponse()->getContent();
        $this->assertStringContainsString('empires/game/'.$player->game->id, $html);
    }

    #[Test]
    public function choosingAGiftAppendsAZeroCostLineAndValidatingOwnsTheGiftedAdvance(): void
    {
        $player = PlayerBuilder::named('Alice')->persist($this->entityManager);
        $client = self::getContainer()->get('test.client');
        $cart = Cart::fromKeys(['anatomy']);
        $cart->withGift('anatomy', 'astronavigation');
        $this->cartFor($client, $player, $cart);

        $this->createCart($player, $client)->call('checkout');

        $order = $this->freshOrderRepository()->findOneByPlayerAndWindow($player, $player->game->currentTurn);
        $this->assertInstanceOf(Order::class, $order);
        $this->assertSame(['anatomy', 'astronavigation'], $order->keys());

        $giftLine = $order->lines()[1];
        $this->assertSame('astronavigation', $giftLine->key);
        $this->assertSame(0, $giftLine->netCost);
        $this->assertInstanceOf(AppliedPromotion::class, $giftLine->promotion);
        $this->assertSame(PromotionType::Gift, $giftLine->promotion->type);
        $this->assertSame('anatomy', $giftLine->promotion->source);

        self::getContainer()->get(OrderValidator::class)->validate($order);

        $reloadedPlayer = $this->reloadPlayer($player);
        $this->assertContains('anatomy', $reloadedPlayer->advances);
        $this->assertContains('astronavigation', $reloadedPlayer->advances);
    }

    #[Test]
    public function editPendingOrderReloadsTheGiftChoiceIntoTheCart(): void
    {
        $player = PlayerBuilder::named('Alice')->persist($this->entityManager);
        $client = self::getContainer()->get('test.client');
        $cart = Cart::fromKeys(['anatomy']);
        $cart->withGift('anatomy', 'astronavigation');
        $this->cartFor($client, $player, $cart);

        $this->createCart($player, $client)->call('checkout');

        $freshComponent = $this->createLiveComponent('Shop', ['player' => $player]);
        $freshComponent->call('editPendingOrder');

        $rendered = $freshComponent->render()->toString();

        $this->assertStringContainsString('Free gift: Astronavigation', $rendered);
    }

    #[Test]
    public function allocatingTheFullOptionPoolEnablesSubmitAndPersistsTheAllocationOnTheOrder(): void
    {
        $player = PlayerBuilder::named('Alice')->persist($this->entityManager);
        $client = self::getContainer()->get('test.client');
        $cart = Cart::fromKeys(['monument']);
        $cart->withAllocation('monument', 'craft', 10);
        $cart->withAllocation('monument', 'science', 10);
        $this->cartFor($client, $player, $cart);

        $crawler = $this->createLiveComponent('Shop', ['player' => $player], $client)->render()->crawler();
        $this->assertFalse($crawler->filter('[data-live-action-param="checkout"]')->getNode(0)->hasAttribute('disabled'));

        $this->createCart($player, $client)->call('checkout');

        $order = $this->freshOrderRepository()->findOneByPlayerAndWindow($player, $player->game->currentTurn);
        $this->assertInstanceOf(Order::class, $order);
        $this->assertSame(OrderStatus::Pending, $order->status);

        $line = $order->lines()[0];
        $this->assertInstanceOf(AppliedPromotion::class, $line->promotion);
        $this->assertSame(PromotionType::Option, $line->promotion->type);
        $this->assertSame(['craft' => 10, 'science' => 10], $line->promotion->allocation);
    }

    #[Test]
    public function validatingAMonumentOrderCreditsTheDiscountsTable(): void
    {
        $player = PlayerBuilder::named('Alice')->persist($this->entityManager);
        $client = self::getContainer()->get('test.client');
        $cart = Cart::fromKeys(['monument']);
        $cart->withAllocation('monument', 'craft', 10);
        $cart->withAllocation('monument', 'science', 10);
        $this->cartFor($client, $player, $cart);

        $this->createCart($player, $client)->call('checkout');

        $order = $this->freshOrderRepository()->findOneByPlayerAndWindow($player, $player->game->currentTurn);
        self::getContainer()->get(OrderValidator::class)->validate($order);

        $rendered = $this->createLiveComponent('Shop', ['player' => $player])->render()->toString();

        // Craft is 20: monument's own recipe already credits craft+10 to its owner
        // (config/game/advances.yaml), on top of which the +10 Option allocation
        // merges in; science carries no such recipe credit, so its 10 is the bonus alone.
        $this->assertMatchesRegularExpression('/Craft<\/td>\s*<td><b>20<\/b><\/td>/', $rendered);
        $this->assertMatchesRegularExpression('/Science<\/td>\s*<td><b>10<\/b><\/td>/', $rendered);
    }

    #[Test]
    public function editPendingOrderReloadsTheAllocationIntoTheCart(): void
    {
        $player = PlayerBuilder::named('Alice')->persist($this->entityManager);
        $client = self::getContainer()->get('test.client');
        $cart = Cart::fromKeys(['monument']);
        $cart->withAllocation('monument', 'craft', 10);
        $cart->withAllocation('monument', 'science', 10);
        $this->cartFor($client, $player, $cart);

        $this->createCart($player, $client)->call('checkout');

        $freshComponent = $this->createLiveComponent('Shop', ['player' => $player]);
        $freshComponent->call('editPendingOrder');

        $crawler = $freshComponent->render()->crawler();

        $this->assertStringContainsString('Remaining: 0', $crawler->filter('.allocation-picker')->text());
        // Category order is art/civic/craft/religion/science (App\Rules\Ruleset\Category): only
        // craft and science were allocated, 10 each.
        $this->assertSame(['0', '0', '10', '0', '10'], $crawler->filter('.allocation-picker__value')->each(static fn ($node) => $node->text()));
        $this->assertFalse($crawler->filter('[data-live-action-param="checkout"]')->getNode(0)->hasAttribute('disabled'));
    }

    private function createPendingOrder(Player $player, string ...$slugs): Order
    {
        $order = new Order($player, $player->game->currentTurn);
        $order->replaceLines(array_map(static fn (string $slug): OrderLine => new OrderLine($slug, 0), $slugs));
        $this->entityManager->persist($order);
        $this->entityManager->flush();

        return $order;
    }

    /**
     * Writes straight into the session-backed CartStorageInterface port (Cart has
     * no add() action of its own any more) and points $client's cookie jar at
     * that session, so the Shop component under test — driven by the same
     * $client — reads the same cart back. 'test.client' is registered
     * share(false) (Symfony\Bundle\FrameworkBundle\Resources\config\test.php),
     * so $client must be the exact instance later passed to createLiveComponent().
     */
    private function cartFor(KernelBrowser $client, Player $player, Cart $cart): void
    {
        $session = self::getContainer()->get('session.factory')->createSession();
        $request = new Request();
        $request->setSession($session);
        $requestStack = self::getContainer()->get(RequestStack::class);
        $requestStack->push($request);
        self::getContainer()->get(CartStorageInterface::class)->save((string) $player->id, $cart);
        $requestStack->pop();
        $session->save();

        $client->getCookieJar()->set(new Cookie($session->getName(), $session->getId()));
    }

    /** Checkout now lives on the Cart LiveComponent (see App\Presentation\Component\Cart::checkout) — Shop only hosts it. */
    private function createCart(Player $player, KernelBrowser $client): TestLiveComponent
    {
        return $this->createLiveComponent('Cart', [
            'player' => $player,
            'storageKey' => (string) $player->id,
        ], $client);
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

    private function getShopComponent(object $component): object
    {
        // InteractsWithLiveComponents' TestLiveComponent exposes the underlying
        // component instance via getComponent() to inspect non-rendered state.
        return $component->component();
    }
}
