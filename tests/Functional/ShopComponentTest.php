<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\GameSession;
use App\Entity\Order;
use App\Entity\Player;
use App\Repository\OrderRepository;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\UX\LiveComponent\Test\InteractsWithLiveComponents;

final class ShopComponentTest extends WebTestCase
{
    use InteractsWithLiveComponents;

    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        self::bootKernel();

        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
    }

    #[Test]
    public function rendersAllFiftyOneAdvancesInTheShop(): void
    {
        $player = $this->createPlayer();

        $rendered = $this->createLiveComponent('Shop', ['player' => $player])->render()->toString();

        self::assertSame(51, substr_count($rendered, '<article'));
    }

    #[Test]
    public function addToCartMarksTheProductInCartAndUpdatesTheTotal(): void
    {
        $player = $this->createPlayer();

        $component = $this->createLiveComponent('Shop', ['player' => $player]);
        $component->call('addToCart', ['key' => 'pottery']);

        $rendered = $component->render()->toString();

        self::assertStringContainsString('id="product-pottery"', $rendered);
        self::assertStringContainsString('data-in-cart', $rendered);
        self::assertStringContainsString('Total: 60', $rendered);
    }

    #[Test]
    public function democracyIsDiscountedForAPlayerOwningAgriculture(): void
    {
        $player = $this->createPlayer();
        $player->ownAdvances(['agriculture']);
        $this->entityManager->flush();

        $rendered = $this->createLiveComponent('Shop', ['player' => $player])->render()->toString();

        self::assertMatchesRegularExpression(
            '/id="product-democracy".*?data-price-net>200</s',
            $rendered,
        );
    }

    #[Test]
    public function discountsAreRenderedInTheKioskWithTheOwnedCategoryColors(): void
    {
        $player = $this->createPlayer();
        $player->ownAdvances(['agriculture']);
        $this->entityManager->flush();

        $rendered = $this->createLiveComponent('Shop', ['player' => $player])->render()->toString();

        self::assertStringContainsString('--category-color', $rendered);
        self::assertStringContainsString('--category-color: #F7941E', $rendered);
        self::assertStringContainsString('--category-color: #39B54A', $rendered);
    }

    #[Test]
    public function submitOrderCreatesAPendingOrderAndEmptiesTheCart(): void
    {
        $player = $this->createPlayer();

        $component = $this->createLiveComponent('Shop', ['player' => $player]);
        $component->call('addToCart', ['key' => 'pottery']);
        $component->call('submitOrder');

        $order = $this->freshOrderRepository()->findOneByPlayerAndTurn($player, $player->game->currentTurn);
        self::assertNotNull($order);
        self::assertSame(['pottery'], $order->lines);

        $rendered = $component->render()->toString();
        self::assertStringContainsString('Your cart is empty.', $rendered);
    }

    #[Test]
    public function editPendingOrderReloadsItsLinesIntoTheCart(): void
    {
        $player = $this->createPlayer();
        $order = $this->createPendingOrder($player, 'pottery', 'agriculture');

        $component = $this->createLiveComponent('Shop', ['player' => $player]);
        $component->call('editPendingOrder');

        $rendered = $component->render()->toString();

        self::assertStringContainsString('data-in-cart', $rendered);
        self::assertSame(2, substr_count($rendered, 'data-live-action-param="removeFromCart"'));
        self::assertNotNull($order->id);
    }

    #[Test]
    public function aValidatedOrderLocksTheKioskAndAddToCartIsANoOp(): void
    {
        $player = $this->createPlayer();
        $order = $this->createPendingOrder($player, 'pottery');
        $order->validate([['key' => 'pottery', 'netCost' => 60]], 60, 60);
        $this->entityManager->flush();

        $component = $this->createLiveComponent('Shop', ['player' => $player]);
        self::assertTrue($this->getShopComponent($component)->isLockedForTurn());

        $component->call('addToCart', ['key' => 'agriculture']);

        $rendered = $component->render()->toString();
        self::assertStringNotContainsString('data-in-cart', $rendered);
        self::assertStringContainsString('Order validated for this turn.', $rendered);
    }

    #[Test]
    public function mercureRefreshFiltersToTurnChangedAndOrderValidated(): void
    {
        $player = $this->createPlayer();

        $rendered = $this->createLiveComponent('Shop', ['player' => $player])->render()->toString();

        self::assertStringContainsString('data-mercure-refresh-events-value', $rendered);
        self::assertStringContainsString('turn-changed', $rendered);
        self::assertStringContainsString('order-validated', $rendered);
    }

    #[Test]
    public function getPlayerShopReturnsTwoHundredWithLiveAndMercureWiring(): void
    {
        $player = $this->createPlayer();

        // setUp() already booted the kernel for the live-component tests above,
        // so createClient() (which forbids a pre-booted kernel) is not an option:
        // fetch the test client from the container and register it manually for
        // the assertResponse*/assertSelector* helpers, exactly what createClient()
        // does under the hood.
        $client = self::getClient(self::getContainer()->get('test.client'));
        $client->request('GET', '/game/'.$player->game->slug.'/player/'.$player->slug.'/shop');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-controller~="live"]');

        $html = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('empires/game/'.$player->game->id, $html);
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

    private function createPendingOrder(Player $player, string ...$slugs): Order
    {
        $order = new Order($player, $player->game->currentTurn);
        $order->replaceLines($slugs);
        $this->entityManager->persist($order);
        $this->entityManager->flush();

        return $order;
    }

    private function freshOrderRepository(): OrderRepository
    {
        return self::getContainer()->get(OrderRepository::class);
    }

    private function getShopComponent(object $component): object
    {
        // InteractsWithLiveComponents' TestLiveComponent exposes the underlying
        // component instance via getComponent() to inspect non-rendered state.
        return $component->component();
    }
}
