<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\GameSession;
use App\Entity\Order;
use App\Entity\Player;
use App\Repository\OrderRepository;
use App\Shop\Cart;
use App\Shop\CartStorageInterface;
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
 * The Cart LiveComponent is shared between the player kiosk (Shop, storageKey
 * = player id) and the operator POS (PlayerOrders, storageKey = 'pos.'.player
 * id) — see App\Component\Cart. It only owns cart lines/gift/allocation/total;
 * the product grid and the add() action live on App\Component\ProductGrid and
 * its hosts, covered by ProductGridComponentTest/ShopComponentTest/PosConsoleTest.
 */
final class CartComponentTest extends WebTestCase
{
    use InteractsWithLiveComponents;

    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        self::bootKernel();

        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
    }

    #[Test]
    public function removeRemovesTheGivenKey(): void
    {
        $player = $this->createPlayer();
        $client = self::getContainer()->get('test.client');
        $this->seedCart($client, (string) $player->id, 'pottery', 'democracy');

        $component = $this->createCart($player, client: $client);
        $rendered = $component->call('remove', ['key' => 'pottery'])->render()->toString();

        $cartLines = $this->extractCartLinesSection($rendered);
        $this->assertSame(1, substr_count($cartLines, 'class="shop__cart-line"'));
        $this->assertStringNotContainsString('Pottery', $cartLines);
        $this->assertStringContainsString('Democracy', $cartLines);
    }

    #[Test]
    public function cartShowsTheDiscountBadgeAndStruckThroughOriginalPriceWhenLibraryIsAdded(): void
    {
        $player = $this->createPlayer();
        $client = self::getContainer()->get('test.client');
        $this->seedCart($client, (string) $player->id, 'library', 'democracy');

        $rendered = $this->createCart($player, client: $client)->render()->toString();

        $this->assertStringContainsString('shop__cart-line-badge', $rendered);
        $this->assertStringContainsString('−40 library', $rendered);
        $this->assertStringContainsString('shop__cart-line-original', $rendered);
        $this->assertStringContainsString('Total: 400', $rendered);
    }

    #[Test]
    public function addingAnatomyShowsTheGiftPickerForAnEligibleScienceCandidate(): void
    {
        $player = $this->createPlayer();
        $client = self::getContainer()->get('test.client');
        $this->seedCart($client, (string) $player->id, 'anatomy');

        $rendered = $this->createCart($player, client: $client)->render()->toString();

        $this->assertStringContainsString('gift-picker', $rendered);
        $this->assertStringContainsString('Astronavigation', $rendered);
    }

    #[Test]
    public function addingMonumentShowsTheAllocationPickerWithTheFullRemainingPool(): void
    {
        $player = $this->createPlayer();
        $client = self::getContainer()->get('test.client');
        $this->seedCart($client, (string) $player->id, 'monument');

        $crawler = $this->createCart($player, client: $client)->render()->crawler();

        $this->assertStringContainsString('Remaining: 20', $crawler->filter('.allocation-picker')->text());
    }

    /**
     * checkout() converged onto Cart from Shop/PlayerOrders (see App\Component\Cart)
     * specifically to fix the parent submit/checkout button going stale after a
     * Cart-only re-render; the cross-component AJAX re-render itself isn't
     * observable in this single-component harness (covered by the browser
     * check), but the emitUp('orderPlaced') signal it relies on is.
     */
    #[Test]
    public function checkoutWithACompleteCartCreatesTheOrderAndEmitsOrderPlaced(): void
    {
        $player = $this->createPlayer();
        $client = self::getContainer()->get('test.client');
        $this->seedCart($client, (string) $player->id, 'pottery');

        $component = $this->createCart($player, client: $client);
        $component->call('checkout');

        $order = self::getContainer()->get(OrderRepository::class)
            ->findOneByPlayerAndWindow($player, $player->game->currentTurn);
        $this->assertInstanceOf(Order::class, $order);
        $this->assertSame(['pottery'], $order->keys());

        $this->assertComponentEmitEvent($component, 'orderPlaced');
    }

    #[Test]
    public function checkoutWithAnIncompleteAllocationIsBlockedAndShowsTheGuardMessage(): void
    {
        $player = $this->createPlayer();
        $client = self::getContainer()->get('test.client');
        $cart = Cart::fromKeys(['monument']);
        $cart->withAllocation('monument', 'science', 5);
        $this->saveCart($client, (string) $player->id, $cart);

        $component = $this->createCart($player, client: $client);
        $rendered = $component->call('checkout')->render()->toString();

        $this->assertNull(self::getContainer()->get(OrderRepository::class)
            ->findOneByPlayerAndWindow($player, $player->game->currentTurn));
        $this->assertStringContainsString('Finish allocating the bonus for', $rendered);
        $this->assertStringContainsString('monument', $rendered);
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

    private function createCart(Player $player, ?string $storageKey = null, ?KernelBrowser $client = null): TestLiveComponent
    {
        return $this->createLiveComponent('Cart', [
            'player' => $player,
            'storageKey' => $storageKey ?? (string) $player->id,
        ], $client);
    }

    /**
     * Writes straight into the session-backed CartStorageInterface port (Cart has
     * no add() action of its own any more) and points $client's cookie jar at
     * that session, so the LiveComponent's own HTTP round-trip — driven by the
     * same $client — reads the same cart back. 'test.client' is registered
     * share(false) (Symfony\Bundle\FrameworkBundle\Resources\config\test.php),
     * so $client must be the exact instance later passed to createCart().
     */
    private function seedCart(KernelBrowser $client, string $storageKey, string ...$keys): void
    {
        $this->saveCart($client, $storageKey, Cart::fromKeys($keys));
    }

    private function saveCart(KernelBrowser $client, string $storageKey, Cart $cart): void
    {
        $session = self::getContainer()->get('session.factory')->createSession();
        $request = new Request();
        $request->setSession($session);
        $requestStack = self::getContainer()->get(RequestStack::class);
        $requestStack->push($request);
        self::getContainer()->get(CartStorageInterface::class)->save($storageKey, $cart);
        $requestStack->pop();
        $session->save();

        $client->getCookieJar()->set(new Cookie($session->getName(), $session->getId()));
    }

    /** Scopes assertions to the cart's line items, as opposed to the product grid (which repeats advance names). */
    private function extractCartLinesSection(string $html): string
    {
        $start = strpos($html, '<ul class="shop__cart-lines">');
        $this->assertNotFalse($start, 'shop__cart-lines not found in rendered output.');

        $end = strpos($html, '</ul>', $start);
        $this->assertNotFalse($end, 'Closing </ul> for shop__cart-lines not found in rendered output.');

        return substr($html, $start, $end - $start);
    }
}
