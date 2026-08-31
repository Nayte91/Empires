<?php

declare(strict_types=1);

namespace App\Tests\Component;

use App\Presentation\Shop\CartKey;
use App\State\Order;
use App\State\Player;
use App\Engine\Shop\AdvanceFulfillment;
use App\Presentation\Shop\CatalogView;
use App\Rules\Ruleset\Advance;
use App\Rules\Ruleset\AdvanceRegistry;
use Symfony\Component\DomCrawler\Crawler;
use App\Tests\Support\Fixture\PlayerBuilder;
use App\Tests\Support\GameFixtureTrait;
use Userforged\ShopEngine\Cart;
use Userforged\ShopEngine\CartStorageInterface;
use Userforged\ShopEngine\Dto\OrderLine;
use Userforged\ShopEngine\OrderStatus;
use Userforged\ShopEngine\Promotion\AppliedPromotion;
use Userforged\ShopEngine\Promotion\PromotionType;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\UX\TwigComponent\Test\InteractsWithTwigComponents;
use Symfony\UX\TwigComponent\Test\RenderedComponent;

/**
 * App\Presentation\Component\Catalog is a plain TwigComponent (no add() action of its
 * own — the LiveAction lives on its host, Shop or PlayerOrders, see
 * ShopComponentTest/PosConsoleTest) rendering the catalog for a given
 * storageKey. These tests exercise it directly via renderTwigComponent().
 */
final class CatalogComponentTest extends KernelTestCase
{
    use GameFixtureTrait;
    use InteractsWithTwigComponents;

    protected function setUp(): void
    {
        $this->initEntityManager();

        // renderTwigComponent() renders in-process, with no HTTP request to carry a
        // session — the CartStorageInterface port is session-backed, so a request with
        // its own session is pushed once here for every Catalog render/cart-storage
        // seed in this file to share.
        $request = new Request();
        $request->setSession(self::getContainer()->get('session.factory')->createSession());
        self::getContainer()->get(RequestStack::class)->push($request);
    }

    #[Test]
    public function rendersAllFiftyOneAvailableAdvancesAsTiles(): void
    {
        $player = PlayerBuilder::named('Alice')->persist($this->entityManager);

        $crawler = $this->renderCatalog($player, (string) $player->id)->crawler();

        $this->assertCount(51, $crawler->filter('button[id^="product-"]'));
    }

    #[Test]
    public function ownedAdvancesAreExcludedFromTheCatalog(): void
    {
        $player = PlayerBuilder::named('Alice')->persist($this->entityManager);
        $player->ownAdvances(['pottery']);
        $this->entityManager->flush();

        $rendered = $this->renderCatalog($player, (string) $player->id)->toString();

        $this->assertStringNotContainsString('id="product-pottery"', $rendered);
    }

    #[Test]
    public function democracyIsDiscountedForAPlayerOwningAgriculture(): void
    {
        $player = $this->discountedPlayer();

        $rendered = $this->renderCatalog($player, (string) $player->id)->toString();

        $this->assertMatchesRegularExpression('/id="product-democracy".*?data-price-net>200</s', $rendered);
    }

    #[Test]
    public function aProductInTheCartLeavesTheCatalogueWhileTheRestStays(): void
    {
        $player = PlayerBuilder::named('Alice')->persist($this->entityManager);
        self::getContainer()->get(CartStorageInterface::class)->save((string) $player->id, Cart::fromKeys(['pottery']));

        $crawler = $this->renderCatalog($player, (string) $player->id)->crawler();

        $this->assertCount(0, $crawler->filter('#product-pottery'));
        $this->assertCount(1, $crawler->filter('#product-democracy'));
    }

    /** Taking an advance out of the cart puts it back on the shelf — the grid is symmetric. */
    #[Test]
    public function aProductRemovedFromTheCartReturnsToTheCatalogue(): void
    {
        $player = PlayerBuilder::named('Alice')->persist($this->entityManager);
        $storage = self::getContainer()->get(CartStorageInterface::class);
        $storage->save((string) $player->id, Cart::fromKeys(['pottery']));

        $this->assertCount(0, $this->renderCatalog($player, (string) $player->id)->crawler()->filter('#product-pottery'));

        $storage->save((string) $player->id, Cart::fromKeys([]));

        $this->assertCount(1, $this->renderCatalog($player, (string) $player->id)->crawler()->filter('#product-pottery'));
    }

    /**
     * `locked` is the host's turn-lock reaching the card (App\Presentation\Component\Shop::isLockedForTurn,
     * now carried by CatalogView::kiosk()): a product that is neither owned nor in the cart must
     * still refuse the add once the turn's order has been validated. Both renders are kiosk views,
     * so the lock is the single field between them.
     */
    #[Test]
    public function lockedDisablesTheAddButtonOfAnOtherwiseAvailableProduct(): void
    {
        $player = PlayerBuilder::named('Alice')->persist($this->entityManager);

        $unlocked = $this->renderCatalog($player, (string) $player->id, CatalogView::kiosk(locked: false, remainingBudget: null))->crawler();
        $locked = $this->renderCatalog($player, (string) $player->id, CatalogView::kiosk(locked: true, remainingBudget: null))->crawler();

        $this->assertFalse($unlocked->filter('#product-pottery')->getNode(0)->hasAttribute('disabled'));
        $this->assertTrue($locked->filter('#product-pottery')->getNode(0)->hasAttribute('disabled'));
    }

    /**
     * The lock and the loading plugin fight over the same attribute: an unconditional
     * `data-loading="addAttribute(disabled)"` is stripped on mount (symfony/ux#372), which would
     * silently re-enable the button the lock had just disabled. It must be absent when locked.
     */
    #[Test]
    public function aLockedTileCarriesNoLoadingDirectiveThatWouldReEnableIt(): void
    {
        $player = PlayerBuilder::named('Alice')->persist($this->entityManager);

        $locked = $this->renderCatalog($player, (string) $player->id, CatalogView::kiosk(locked: true, remainingBudget: null))->crawler();

        $this->assertNull($locked->filter('#product-pottery')->attr('data-loading'));
    }

    /**
     * `remainingBudget` is the kiosk's planning band reaching the tile (App\Presentation\Component\Shop::
     * getRemainingBudget, now carried by CatalogView::kiosk()), and the one term #33 adds to the same
     * disabling expression `locked` feeds. Equality is affordable: a remainder of exactly the price
     * still buys the card, so the boundary falls between 60 and 59 rather than around them.
     */
    #[Test]
    #[DataProvider('provideATilesAvailabilityFollowsTheRemainingBudgetCases')]
    public function aTilesAvailabilityFollowsTheRemainingBudget(?int $remainingBudget, bool $expectedDisabled): void
    {
        $player = PlayerBuilder::named('Alice')->persist($this->entityManager);

        $crawler = $this->renderCatalog($player, (string) $player->id, CatalogView::kiosk(locked: false, remainingBudget: $remainingBudget))->crawler();

        $this->assertSame($expectedDisabled, $crawler->filter('#product-pottery')->getNode(0)->hasAttribute('disabled'));
    }

    /** Pottery costs 60 and this player owns nothing, so no discount stands between price and remainder. */
    public static function provideATilesAvailabilityFollowsTheRemainingBudgetCases(): iterable
    {
        yield 'no budget at all constrains nothing' => [null, false];

        yield 'a remainder equal to the price still affords it' => [60, false];

        yield 'a remainder one short of the price disables it' => [59, true];

        yield 'a remainder of zero disables it' => [0, true];

        yield 'a remainder already gone negative disables it' => [-40, true];
    }

    /**
     * The row above proves zero disables *one* tile; every advance in this game costs something, so
     * zero must leave nothing at all buyable. Counting the shelf twice — once whole, once disabled —
     * is what keeps the second count from passing on an empty selector.
     */
    #[Test]
    public function aRemainderOfZeroDisablesEveryTileOnTheShelf(): void
    {
        $player = PlayerBuilder::named('Alice')->persist($this->entityManager);

        $crawler = $this->renderCatalog($player, (string) $player->id, CatalogView::kiosk(locked: false, remainingBudget: 0))->crawler();

        $this->assertCount(51, $crawler->filter('button[id^="product-"]'));
        $this->assertCount(51, $crawler->filter('button[id^="product-"][disabled]'));
    }

    /**
     * The tile shows, and the checkout charges, the discounted price — so a remainder that affords
     * what is printed must not be refused on the strength of a list price the player never pays.
     * Democracy lists at 220 and costs this player 200: comparing against the list price would
     * disable it at a remainder of 200.
     */
    #[Test]
    public function aTileIsWeighedAgainstItsDiscountedPriceRatherThanItsListPrice(): void
    {
        $player = $this->discountedPlayer();

        $atTheNetCost = $this->renderCatalog($player, (string) $player->id, CatalogView::kiosk(locked: false, remainingBudget: 200))->crawler();
        $oneShortOfIt = $this->renderCatalog($player, (string) $player->id, CatalogView::kiosk(locked: false, remainingBudget: 199))->crawler();

        $this->assertSame('200', $atTheNetCost->filter('#product-democracy [data-price-net]')->text());
        $this->assertFalse($atTheNetCost->filter('#product-democracy')->getNode(0)->hasAttribute('disabled'));
        $this->assertTrue($oneShortOfIt->filter('#product-democracy')->getNode(0)->hasAttribute('disabled'));
    }

    /**
     * Same trap as the turn lock one test above, down the other branch of the same expression: an
     * unconditional `data-loading="addAttribute(disabled)"` is stripped on mount (symfony/ux#372),
     * which would re-enable a tile the budget had just put out of reach.
     */
    #[Test]
    public function anOverBudgetTileCarriesNoLoadingDirectiveThatWouldReEnableIt(): void
    {
        $player = PlayerBuilder::named('Alice')->persist($this->entityManager);

        $overBudget = $this->renderCatalog($player, (string) $player->id, CatalogView::kiosk(locked: false, remainingBudget: 0))->crawler();

        $this->assertNull($overBudget->filter('#product-pottery')->attr('data-loading'));
    }

    #[Test]
    public function productsRenderAsButtonsWithNameAndNetCostAndBiCategoryAdvancesCarryTwoStripeColors(): void
    {
        $player = PlayerBuilder::named('Alice')->persist($this->entityManager);

        $crawler = $this->renderCatalog($player, CartKey::pos($player, $player->game->currentTurn))->crawler();

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

    /**
     * The tile shows only the net cost, so without the struck-through original the whole discount
     * machinery — and the panel the shop devotes to it — becomes invisible at the point of choice.
     */
    #[Test]
    public function aDiscountedTileKeepsShowingThePriceTheAdvanceWouldOtherwiseCost(): void
    {
        $player = $this->discountedPlayer();

        $crawler = $this->renderCatalog($player, (string) $player->id)->crawler();

        $democracy = $crawler->filter('#product-democracy');
        $this->assertSame('220', $democracy->filter('s')->text());
        $this->assertSame('200', $democracy->filter('[data-price-net]')->text());
    }

    /** An advance at full price shows one number: a struck-through equal price would read as a discount. */
    #[Test]
    public function anUndiscountedTileShowsNoOriginalPrice(): void
    {
        $player = PlayerBuilder::named('Alice')->persist($this->entityManager);

        $crawler = $this->renderCatalog($player, (string) $player->id)->crawler();

        $this->assertCount(0, $crawler->filter('#product-pottery s'));
    }

    /**
     * The shelf inherits AdvanceRegistry's list-price order, which discounts then contradict: most
     * of the catalogue shows a price that no longer matches its position. The kiosk view is the one
     * that reorders it.
     */
    #[Test]
    public function theKioskViewOrdersTheCatalogueByWhatThePlayerActuallyPays(): void
    {
        $player = $this->discountedPlayer();
        $kiosk = CatalogView::kiosk(locked: false, remainingBudget: null);

        $keys = $this->productKeys($this->renderCatalog($player, (string) $player->id, $kiosk));
        $netCosts = $this->renderCatalog($player, (string) $player->id, $kiosk)
            ->crawler()->filter('[data-price-net]')->each(static fn (Crawler $node): int => (int) $node->text());

        $ascending = $netCosts;
        sort($ascending);

        $this->assertSame($ascending, $netCosts);
        $this->assertNotSame($this->productKeys($this->renderCatalog($player, (string) $player->id, CatalogView::pos())), $keys);
    }

    /**
     * The other half of the guard, and the one that matters: the POS view must leave the order
     * exactly as AdvanceRegistry handed it over. Asserting that list prices ascend would pass
     * without any sort at all and prove nothing.
     */
    #[Test]
    public function thePosViewLeavesTheCatalogueInTheOrderTheRegistryProvides(): void
    {
        $player = $this->discountedPlayer();

        $keys = $this->productKeys($this->renderCatalog($player, (string) $player->id, CatalogView::pos()));

        $registryOrder = array_values(array_filter(
            array_map(
                static fn (Advance $advance): string => $advance->key,
                self::getContainer()->get(AdvanceRegistry::class)->getAdvances(),
            ),
            static fn (string $key): bool => \in_array($key, $keys, true),
        ));

        $this->assertSame($registryOrder, $keys);
    }

    #[Test]
    public function storageKeyIsolatesTwoCartsForTheSamePlayer(): void
    {
        $player = PlayerBuilder::named('Alice')->persist($this->entityManager);
        $shopKey = CartKey::shop($player);
        $posKey = CartKey::pos($player, $player->game->currentTurn);

        $storage = self::getContainer()->get(CartStorageInterface::class);
        $storage->save($shopKey, Cart::fromKeys(['pottery']));
        $storage->save($posKey, Cart::fromKeys(['democracy']));

        $shopCrawler = $this->renderCatalog($player, $shopKey)->crawler();
        $this->assertCount(0, $shopCrawler->filter('#product-pottery'));
        $this->assertCount(1, $shopCrawler->filter('#product-democracy'));

        $posCrawler = $this->renderCatalog($player, $posKey)->crawler();
        $this->assertCount(0, $posCrawler->filter('#product-democracy'));
        $this->assertCount(1, $posCrawler->filter('#product-pottery'));
    }

    #[Test]
    public function catalogReflectsDiscountsCreditedByAPreviouslyValidatedMonumentOrder(): void
    {
        $player = PlayerBuilder::named('Alice')->persist($this->entityManager);
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
        $rendered = $this->renderCatalog($player, (string) $player->id)->toString();
        $this->assertMatchesRegularExpression('/id="product-anatomy".*?data-price-net>260</s', $rendered);
    }

    /**
     * The component has no presentation default left to inherit — App\Presentation\Shop\CatalogView is
     * required, and only its two named constructors can build one. CatalogView::pos() stands in for
     * the tests that say nothing about presentation: unlocked, list-price order, no budget, which is
     * the shelf as the registry hands it over.
     */
    private function renderCatalog(Player $player, string $storageKey, ?CatalogView $view = null): RenderedComponent
    {
        return $this->renderTwigComponent('organisms:Catalog', [
            'player' => $player,
            'storageKey' => $storageKey,
            'view' => $view ?? CatalogView::pos(),
        ]);
    }

    /** Owning agriculture discounts a good part of the catalogue, which is what makes the two orders differ. */
    private function discountedPlayer(): Player
    {
        $player = PlayerBuilder::named('Alice')->persist($this->entityManager);
        self::getContainer()->get(AdvanceFulfillment::class)->grant($player->id, ['agriculture']);
        $this->entityManager->flush();

        return $player;
    }

    /** @return list<string> */
    private function productKeys(RenderedComponent $rendered): array
    {
        return $rendered->crawler()->filter('[id^="product-"]')->each(
            static fn (Crawler $node): string => substr((string) $node->attr('id'), \strlen('product-')),
        );
    }

}
