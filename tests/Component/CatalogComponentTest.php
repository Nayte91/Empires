<?php

declare(strict_types=1);

namespace App\Tests\Component;

use App\Presentation\Shop\CartKey;
use App\State\Player;
use App\Engine\Shop\AdvanceFulfillment;
use App\Presentation\Shop\CatalogView;
use App\Tests\Support\Fixture\OrderBuilder;
use App\Tests\Support\Fixture\PlayerBuilder;
use App\Tests\Support\GameFixtureTrait;
use Userforged\ShopEngine\Cart;
use Userforged\ShopEngine\CartStorageInterface;
use Userforged\ShopEngine\Dto\OrderLine;
use Userforged\ShopEngine\Promotion\AppliedPromotion;
use Userforged\ShopEngine\Promotion\PromotionType;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\UX\TwigComponent\Test\InteractsWithTwigComponents;
use Symfony\UX\TwigComponent\Test\RenderedComponent;

final class CatalogComponentTest extends WebTestCase
{
    use GameFixtureTrait;
    use InteractsWithTwigComponents;

    protected function setUp(): void
    {
        $this->initEntityManager();

        // renderTwigComponent() renders in-process with no request, and CartStorageInterface is
        // session-backed — this pushes one for the whole file.
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
     * An unconditional `data-loading="addAttribute(disabled)"` is stripped on mount
     * (symfony/ux#372), silently re-enabling a button something else had disabled.
     */
    #[Test]
    public function aLockedTileCarriesNoLoadingDirectiveThatWouldReEnableIt(): void
    {
        $player = PlayerBuilder::named('Alice')->persist($this->entityManager);

        $locked = $this->renderCatalog($player, (string) $player->id, CatalogView::kiosk(locked: true, remainingBudget: null))->crawler();

        $this->assertNull($locked->filter('#product-pottery')->attr('data-loading'));
    }

    #[Test]
    #[DataProvider('provideATilesAvailabilityFollowsTheRemainingBudgetCases')]
    public function aTilesAvailabilityFollowsTheRemainingBudget(?int $remainingBudget, bool $expectedDisabled): void
    {
        $player = PlayerBuilder::named('Alice')->persist($this->entityManager);

        $crawler = $this->renderCatalog($player, (string) $player->id, CatalogView::kiosk(locked: false, remainingBudget: $remainingBudget))->crawler();

        $this->assertSame($expectedDisabled, $crawler->filter('#product-pottery')->getNode(0)->hasAttribute('disabled'));
    }

    public static function provideATilesAvailabilityFollowsTheRemainingBudgetCases(): iterable
    {
        yield 'no budget at all constrains nothing' => [null, false];

        yield "a remainder equal to pottery's undiscounted 60 still affords it" => [60, false];

        yield "a remainder of 59, one short of pottery's 60, disables it" => [59, true];

        yield 'a remainder of zero disables it' => [0, true];

        yield 'a remainder already gone negative disables it' => [-40, true];
    }

    /** Counting the shelf whole as well as disabled is what keeps the second count from passing on an empty selector. */
    #[Test]
    public function aRemainderOfZeroDisablesEveryTileOnTheShelf(): void
    {
        $player = PlayerBuilder::named('Alice')->persist($this->entityManager);

        $crawler = $this->renderCatalog($player, (string) $player->id, CatalogView::kiosk(locked: false, remainingBudget: 0))->crawler();

        $this->assertCount(51, $crawler->filter('button[id^="product-"]'));
        $this->assertCount(51, $crawler->filter('button[id^="product-"][disabled]'));
    }

    /** Democracy lists at 220, a number nothing here asserts: weighed against it, this remainder would refuse a card the checkout would charge. */
    #[Test]
    public function aTileIsWeighedAgainstItsDiscountedPriceRatherThanItsListPrice(): void
    {
        $player = $this->discountedPlayer();

        $atDemocracysNetCostOf200 = $this->renderCatalog($player, (string) $player->id, CatalogView::kiosk(locked: false, remainingBudget: 200))->crawler();
        $oneShortOfIt = $this->renderCatalog($player, (string) $player->id, CatalogView::kiosk(locked: false, remainingBudget: 199))->crawler();

        $this->assertSame('200', $atDemocracysNetCostOf200->filter('#product-democracy [data-price-net]')->text());
        $this->assertFalse($atDemocracysNetCostOf200->filter('#product-democracy')->getNode(0)->hasAttribute('disabled'));
        $this->assertTrue($oneShortOfIt->filter('#product-democracy')->getNode(0)->hasAttribute('disabled'));
    }

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

        $mysticism = $crawler->filter('#product-mysticism');
        $this->assertSame('button', $mysticism->nodeName());
        $this->assertStringContainsString('Mysticism', $mysticism->text());
        $this->assertStringContainsString('50', $mysticism->text());
        $this->assertSame('religion', $mysticism->attr('data-advance-category'));
        $this->assertSame('art', $mysticism->attr('data-advance-category-2'));
    }

    #[Test]
    public function aDiscountedTileKeepsShowingThePriceTheAdvanceWouldOtherwiseCost(): void
    {
        $player = $this->discountedPlayer();

        $crawler = $this->renderCatalog($player, (string) $player->id)->crawler();

        $democracy = $crawler->filter('#product-democracy');
        $this->assertSame('220', $democracy->filter('s')->text());
        $this->assertSame('200', $democracy->filter('[data-price-net]')->text());
    }

    #[Test]
    public function anUndiscountedTileShowsNoOriginalPrice(): void
    {
        $player = PlayerBuilder::named('Alice')->persist($this->entityManager);

        $crawler = $this->renderCatalog($player, (string) $player->id)->crawler();

        $this->assertCount(0, $crawler->filter('#product-pottery s'));
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
        OrderBuilder::for($player)
            ->withLine(new OrderLine('monument', 180, new AppliedPromotion(PromotionType::Option, 'monument', allocation: ['craft' => 10, 'science' => 10])))
            ->validated(180)
            ->persist($this->entityManager)
        ;

        // anatomy carries no credit of its own from monument's recipe, so its -10 can only come
        // from the Option allocation bonus.
        $rendered = $this->renderCatalog($player, (string) $player->id)->toString();
        $this->assertMatchesRegularExpression('/id="product-anatomy".*?data-price-net>260</s', $rendered);
    }

    private function renderCatalog(Player $player, string $storageKey, ?CatalogView $view = null): RenderedComponent
    {
        return $this->renderTwigComponent('organisms:Catalog', [
            'player' => $player,
            'storageKey' => $storageKey,
            'view' => $view ?? CatalogView::pos(),
        ]);
    }

    /** Owning agriculture discounts enough of the catalogue to make the two orders differ. */
    private function discountedPlayer(): Player
    {
        $player = PlayerBuilder::named('Alice')->persist($this->entityManager);
        self::getContainer()->get(AdvanceFulfillment::class)->grant($player->id, ['agriculture'], $player->game->currentTurn);
        $this->entityManager->flush();

        return $player;
    }
}
