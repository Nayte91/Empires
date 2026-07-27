<?php

declare(strict_types=1);

namespace App\Tests\Component;

use App\Entity\GameSession;
use App\Entity\Order;
use App\Entity\Player;
use App\Game\Shop\AdvanceFulfillment;
use App\Repository\OrderRepository;
use App\Tests\Support\Fixture\GameBuilder;
use App\Tests\Support\Fixture\PlayerBuilder;
use App\Tests\Support\GameFixtureTrait;
use Userforged\ShopEngine\Cart;
use Userforged\ShopEngine\CartStorageInterface;
use Userforged\ShopEngine\Dto\OrderLine;
use Userforged\ShopEngine\OrderStatus;
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
 * End-to-end acceptance test for the cashier-kiosk pattern: a player builds a
 * cart in their Shop kiosk, submits it, an operator validates it from the
 * console, and the kiosk reflects the resulting lock/ownership state.
 *
 * Cart preconditions are written straight into the session-backed
 * CartStorageInterface port, sharing $client with the Shop/PlayerOrders
 * component under test — see CartComponentTest for Cart's own behavior
 * coverage, ShopComponentTest/PosConsoleTest for the add() LiveAction.
 */
final class KioskOperatorFlowTest extends WebTestCase
{
    use GameFixtureTrait;
    use InteractsWithLiveComponents;

    #[Test]
    public function validatingFreezesLinesGrantsAdvancesAndValidatesTheOrderAndTheCardShowsItsTotalAndVictoryPoints(): void
    {
        [$game, $alice] = $this->createGameWithAliceAndBob();

        $this->submitAliceDemocracyAndPotteryOrder($alice);
        $order = $this->freshOrderRepository()->findOneByPlayerAndWindow($alice, $game->currentTurn);
        $this->assertInstanceOf(Order::class, $order);

        $this->validateOrder($order);

        $reloadedOrder = $this->reloadOrder($order);

        $this->assertSame(OrderStatus::Validated, $reloadedOrder->status);
        // assertEquals, not assertSame — php_unit_strict (phpcs) rewrites this to assertSame,
        // but its own doc flags that as risky "when testing object equality": $reloadedOrder->lines
        // holds the actual OrderLine instances frozen by OrderValidator, never identical (===)
        // to these fresh ones. Keep assertEquals here even if a phpcs re-run tries to flip it.
        $this->assertEquals([
            new OrderLine('democracy', 200),
            new OrderLine('pottery', 50),
        ], $reloadedOrder->lines);
        $this->assertSame(250, $reloadedOrder->total);
        $this->assertSame(['agriculture', 'democracy', 'pottery'], $this->reloadPlayer($alice)->advances);

        $rendered = $this->createLiveComponent('PlayerOrders', [
            'player' => $alice,
            'ordersStamp' => '',
        ])->render()->toString();

        // democracy: 6 points, pottery: 1 point (config/game/advances.yaml).
        $this->assertStringContainsString('Total: 250', $rendered);
        $this->assertStringContainsString('VP: 7', $rendered);
        $this->assertStringContainsString('validated', $rendered);
    }

    #[Test]
    public function aliceKioskLocksForTheTurnAfterValidationWhileBobKioskStaysOpen(): void
    {
        [$game, $alice, $bob] = $this->createGameWithAliceAndBob();

        $this->submitAndValidateAliceOrder($alice, $game);

        $aliceShop = $this->createLiveComponent('Shop', ['player' => $alice]);
        $this->assertTrue($this->getShopComponent($aliceShop)->isLockedForTurn());

        $aliceRendered = $aliceShop->render()->toString();
        $this->assertStringContainsString('Order validated for this turn.', $aliceRendered);
        $this->assertStringNotContainsString('id="product-democracy"', $aliceRendered);
        $this->assertStringContainsString('Democracy', $aliceRendered);

        $bobShop = $this->createLiveComponent('Shop', ['player' => $bob]);
        $this->assertFalse($this->getShopComponent($bobShop)->isLockedForTurn());

        $bobRendered = $bobShop->render()->toString();
        $this->assertMatchesRegularExpression('/id="product-democracy".*?data-price-net>220</s', $bobRendered);
    }

    #[Test]
    public function nextTurnUnlocksAliceKioskAndRepricesHerCatalogWithNewCredits(): void
    {
        [$game, $alice] = $this->createGameWithAliceAndBob();

        $this->submitAndValidateAliceOrder($alice, $game);

        $this->createLiveComponent('OperatorConsole', ['game' => $game])->call('nextTurn');
        $this->assertSame(2, $this->reloadGame($game)->currentTurn);

        $aliceShop = $this->createLiveComponent('Shop', ['player' => $alice]);
        $this->assertFalse($this->getShopComponent($aliceShop)->isLockedForTurn());

        // 'law' costs 150 and grants no direct discount to Alice, but its sole
        // category (civic) receives a 20-point credit from the democracy she
        // now owns: 150 - 20 = 130.
        $rendered = $aliceShop->render()->toString();
        $this->assertMatchesRegularExpression('/id="product-law".*?data-price-net>130</s', $rendered);
    }

    #[Test]
    public function cartAdditionsInAliceKioskNeverAppearInBobKiosk(): void
    {
        [, $alice, $bob] = $this->createGameWithAliceAndBob();
        $client = self::getContainer()->get('test.client');

        $this->cartFor($client, $alice, Cart::fromKeys(['pottery']));

        // createLiveComponent() defaults both kiosks to the same 'test.client'
        // service, i.e. the same session. Isolation is proven by
        // SessionCartStorage keying cart storage per player UUID (see
        // App\Game\Shop\SessionCartStorage::sessionKey()), read here through
        // Shop's own isCartEmpty() (the "Submit my order" gate) rather than
        // through the nested Cart component's rendering, which does not
        // reflect session state when embedded (see ShopComponentTest's
        // editPendingOrder tests).
        $bobCrawler = $this->createLiveComponent('Shop', ['player' => $bob], $client)->render()->crawler();

        $this->assertTrue($bobCrawler->filter('[data-live-action-param="checkout"]')->getNode(0)->hasAttribute('disabled'));
    }

    /** @return array{GameSession, Player, Player} */
    private function createGameWithAliceAndBob(): array
    {
        $game = GameBuilder::create()->build();
        $alice = PlayerBuilder::named('Alice')->in($game)->persist($this->entityManager);
        $bob = PlayerBuilder::named('Bob')->in($game)->persist($this->entityManager);

        // Granted through the real fulfillment service rather than withAdvances(): this file
        // needs the credit ledger the grant posts, not just the advance key.
        self::getContainer()->get(AdvanceFulfillment::class)->grant($alice->id, ['agriculture']);
        $this->entityManager->flush();

        return [$game, $alice, $bob];
    }

    private function submitAliceDemocracyAndPotteryOrder(Player $alice): void
    {
        $client = self::getContainer()->get('test.client');
        $this->cartFor($client, $alice, Cart::fromKeys(['democracy', 'pottery']));

        $this->createCart($alice, $client)->call('checkout');
    }

    private function validateOrder(Order $order): void
    {
        $client = self::getContainer()->get('test.client');
        $component = $this->createLiveComponent('PlayerOrders', [
            'player' => $order->player,
            'ordersStamp' => '',
        ], $client);
        $component->call('openPos', ['turn' => $order->turn]);

        $this->createPosCart($order->player, $order->turn, $client)->call('checkout');
    }

    /** Checkout now lives on the Cart LiveComponent (see App\Component\Cart::checkout) — Shop only hosts it. */
    private function createCart(Player $player, KernelBrowser $client): TestLiveComponent
    {
        return $this->createLiveComponent('Cart', [
            'player' => $player,
            'storageKey' => (string) $player->id,
        ], $client);
    }

    /** Checkout now lives on the Cart LiveComponent (see App\Component\Cart::checkout) — PlayerOrders only hosts it. */
    private function createPosCart(Player $player, int $turn, KernelBrowser $client): TestLiveComponent
    {
        return $this->createLiveComponent('Cart', [
            'player' => $player,
            'storageKey' => 'pos.'.$player->id->toRfc4122(),
            'directSale' => true,
            'window' => $turn,
        ], $client);
    }

    private function submitAndValidateAliceOrder(Player $alice, GameSession $game): void
    {
        $this->submitAliceDemocracyAndPotteryOrder($alice);
        $order = $this->freshOrderRepository()->findOneByPlayerAndWindow($alice, $game->currentTurn);
        $this->assertInstanceOf(Order::class, $order);

        $this->validateOrder($order);
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

    private function freshEntityManager(): EntityManagerInterface
    {
        return self::getContainer()->get(EntityManagerInterface::class);
    }

    private function freshOrderRepository(): OrderRepository
    {
        return self::getContainer()->get(OrderRepository::class);
    }

    private function reloadPlayer(Player $player): Player
    {
        $reloaded = $this->freshEntityManager()->find(Player::class, $player->id);
        $this->assertInstanceOf(Player::class, $reloaded);

        return $reloaded;
    }

    private function reloadGame(GameSession $game): GameSession
    {
        $reloaded = $this->freshEntityManager()->find(GameSession::class, $game->id);
        $this->assertInstanceOf(GameSession::class, $reloaded);

        return $reloaded;
    }

    private function reloadOrder(Order $order): Order
    {
        $reloaded = $this->freshEntityManager()->find(Order::class, $order->id);
        $this->assertInstanceOf(Order::class, $reloaded);

        return $reloaded;
    }

    private function getShopComponent(object $component): object
    {
        return $component->component();
    }
}
