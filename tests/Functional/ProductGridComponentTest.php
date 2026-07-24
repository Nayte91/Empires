<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\GameSession;
use App\Entity\Order;
use App\Entity\Player;
use App\Game\Shop\AdvanceFulfillment;
use Userforged\ShopEngine\Cart;
use Userforged\ShopEngine\CartStorageInterface;
use Userforged\ShopEngine\Dto\OrderLine;
use Userforged\ShopEngine\OrderStatus;
use Userforged\ShopEngine\Promotion\AppliedPromotion;
use Userforged\ShopEngine\Promotion\PromotionType;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\UX\TwigComponent\Test\InteractsWithTwigComponents;
use Symfony\UX\TwigComponent\Test\RenderedComponent;

/**
 * App\Component\ProductGrid is a plain TwigComponent (no add() action of its
 * own — the LiveAction lives on its host, Shop or PlayerOrders, see
 * ShopComponentTest/PosConsoleTest) rendering the catalog for a given
 * storageKey. These tests exercise it directly via renderTwigComponent().
 */
final class ProductGridComponentTest extends KernelTestCase
{
    use InteractsWithTwigComponents;

    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        self::bootKernel();

        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);

        // renderTwigComponent() renders in-process, with no HTTP request to carry a
        // session — the CartStorageInterface port is session-backed, so a request with
        // its own session is pushed once here for every ProductGrid render/cart-storage
        // seed in this file to share.
        $request = new Request();
        $request->setSession(self::getContainer()->get('session.factory')->createSession());
        self::getContainer()->get(RequestStack::class)->push($request);
    }

    #[Test]
    public function rendersAllFiftyOneAvailableAdvancesAsFullProductCards(): void
    {
        $player = $this->createPlayer();

        $rendered = $this->renderProductGrid($player, (string) $player->id)->toString();

        $this->assertSame(51, substr_count($rendered, '<article'));
    }

    #[Test]
    public function ownedAdvancesAreExcludedFromTheProductGrid(): void
    {
        $player = $this->createPlayer();
        $player->ownAdvances(['pottery']);
        $this->entityManager->flush();

        $rendered = $this->renderProductGrid($player, (string) $player->id)->toString();

        $this->assertStringNotContainsString('id="product-pottery"', $rendered);
    }

    #[Test]
    public function theProductCardButtonIsAFullCardOverlayWithAnAccessibleAddLabel(): void
    {
        $player = $this->createPlayer();

        $rendered = $this->renderProductGrid($player, (string) $player->id)->toString();
        $card = $this->extractProductCard($rendered, 'pottery');

        $this->assertStringContainsString('<span class="sr-only">Add Pottery</span>', $card);
        $this->assertStringNotContainsString('>Add<', $card);
    }

    #[Test]
    public function democracyIsDiscountedForAPlayerOwningAgriculture(): void
    {
        $player = $this->createPlayer();
        self::getContainer()->get(AdvanceFulfillment::class)->grant($player->id, ['agriculture']);
        $this->entityManager->flush();

        $rendered = $this->renderProductGrid($player, (string) $player->id)->toString();

        $this->assertMatchesRegularExpression('/id="product-democracy".*?data-price-net>200</s', $rendered);
    }

    #[Test]
    public function productAlreadyInCartHasDisabledOverlayButtonAndInCartMarker(): void
    {
        $player = $this->createPlayer();
        self::getContainer()->get(CartStorageInterface::class)->save((string) $player->id, Cart::fromKeys(['pottery']));

        $rendered = $this->renderProductGrid($player, (string) $player->id)->toString();
        $card = $this->extractProductCard($rendered, 'pottery');

        $this->assertStringContainsString('data-in-cart', $card);
        $this->assertMatchesRegularExpression('/<button[^>]*\bdisabled\b/', $card);
    }

    #[Test]
    public function compactProductsRenderAsButtonsWithNameAndNetCostAndBiCategoryAdvancesCarryTwoStripeColors(): void
    {
        $player = $this->createPlayer();

        $crawler = $this->renderProductGrid($player, $this->posKey($player), compact: true)->crawler();

        $pottery = $crawler->filter('#product-pottery');
        $this->assertSame('button', $pottery->nodeName());
        $this->assertStringContainsString('Pottery', $pottery->text());
        $this->assertStringContainsString('60', $pottery->text());

        // Mysticism spans two categories (religion + art), so it must carry two distinct
        // data-advance-category attributes feeding the tile's striped background.
        $mysticism = $crawler->filter('#product-mysticism');
        $this->assertSame('button', $mysticism->nodeName());
        $this->assertStringContainsString('Mysticism', $mysticism->text());
        $this->assertStringContainsString('50', $mysticism->text());
        $this->assertSame('religion', $mysticism->attr('data-advance-category'));
        $this->assertSame('art', $mysticism->attr('data-advance-category-2'));
    }

    #[Test]
    public function compactPropSwapsFullProductCardsForCompactProductTiles(): void
    {
        $player = $this->createPlayer();

        $fullCard = $this->renderProductGrid($player, (string) $player->id)->crawler();
        $compactTile = $this->renderProductGrid($player, $this->posKey($player), compact: true)->crawler();

        $this->assertSame('article', $fullCard->filter('#product-pottery')->nodeName());
        $this->assertSame('button', $compactTile->filter('#product-pottery')->nodeName());
    }

    #[Test]
    public function storageKeyIsolatesTwoCartsForTheSamePlayer(): void
    {
        $player = $this->createPlayer();
        $shopKey = (string) $player->id;
        $posKey = $this->posKey($player);

        $storage = self::getContainer()->get(CartStorageInterface::class);
        $storage->save($shopKey, Cart::fromKeys(['pottery']));
        $storage->save($posKey, Cart::fromKeys(['democracy']));

        $shopCrawler = $this->renderProductGrid($player, $shopKey)->crawler();
        $this->assertTrue($shopCrawler->filter('#product-pottery')->getNode(0)->hasAttribute('data-in-cart'));
        $this->assertFalse($shopCrawler->filter('#product-democracy')->getNode(0)->hasAttribute('data-in-cart'));

        $posCrawler = $this->renderProductGrid($player, $posKey, compact: true)->crawler();
        $this->assertStringContainsString('product-tile--in-cart', (string) $posCrawler->filter('#product-democracy')->attr('class'));
        $this->assertStringNotContainsString('product-tile--in-cart', (string) $posCrawler->filter('#product-pottery')->attr('class'));
    }

    #[Test]
    public function catalogReflectsDiscountsCreditedByAPreviouslyValidatedMonumentOrder(): void
    {
        $player = $this->createPlayer();
        $order = new Order($player, $player->game->currentTurn);
        $order->freeze(
            [new OrderLine('monument', 180, new AppliedPromotion(PromotionType::Option, 'monument', allocation: ['craft' => 10, 'science' => 10]))],
            180,
        );
        $order->setMarking(OrderStatus::Validated->value);
        $this->entityManager->persist($order);
        $this->entityManager->flush();

        // anatomy (science-only, cost 270) carries no credit of its own from monument's
        // recipe (config/game/advances.yaml), so its -10 net cost drop can only come
        // from the Option allocation bonus credited by validating the monument order.
        $rendered = $this->renderProductGrid($player, (string) $player->id)->toString();
        $this->assertMatchesRegularExpression('/id="product-anatomy".*?data-price-net>260</s', $rendered);
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

    private function renderProductGrid(Player $player, string $storageKey, bool $compact = false, bool $locked = false): RenderedComponent
    {
        return $this->renderTwigComponent('organisms:ProductGrid', [
            'player' => $player,
            'storageKey' => $storageKey,
            'compact' => $compact,
            'locked' => $locked,
        ]);
    }

    private function posKey(Player $player): string
    {
        return 'pos.'.$player->id->toRfc4122();
    }

    /**
     * Scopes assertions to a single product's <article>, as opposed to the
     * whole grid (which renders every other product's markup too).
     */
    private function extractProductCard(string $html, string $key): string
    {
        $idPosition = strpos($html, 'id="product-'.$key.'"');
        $this->assertNotFalse($idPosition, "id=\"product-{$key}\" not found in rendered output.");

        $start = strrpos(substr($html, 0, $idPosition), '<article');
        $this->assertNotFalse($start, "<article> for product '{$key}' not found in rendered output.");

        $end = strpos($html, '</article>', $start);
        $this->assertNotFalse($end, '</article> not found in rendered output.');

        return substr($html, $start, $end - $start);
    }
}
