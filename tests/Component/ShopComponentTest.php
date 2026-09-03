<?php

declare(strict_types=1);

namespace App\Tests\Component;

use App\Engine\Shop\AdvanceFulfillment;
use App\Presentation\Shop\CartKey;
use App\Presentation\Shop\CatalogSort;
use App\State\Order;
use App\State\Player;
use App\Infrastructure\Repository\OrderRepository;
use App\Tests\Support\Fixture\OrderBuilder;
use App\Tests\Support\Fixture\PlayerBuilder;
use App\Tests\Support\GameFixtureTrait;
use App\Tests\Support\ShopFixtureTrait;
use Symfony\Component\DomCrawler\Crawler;
use Userforged\ShopEngine\Cart;
use Userforged\ShopEngine\Dto\OrderLine;
use Userforged\ShopEngine\OrderStatus;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\UX\LiveComponent\Test\InteractsWithLiveComponents;
use Symfony\UX\LiveComponent\Test\TestLiveComponent;

final class ShopComponentTest extends WebTestCase
{
    use GameFixtureTrait;
    use InteractsWithLiveComponents;
    use ShopFixtureTrait;

    #[Test]
    public function discountsAreRenderedInTheKioskWithTheOwnedCategoryColors(): void
    {
        $player = PlayerBuilder::named('Alice')->persist($this->entityManager);
        $player->ownAdvances(['agriculture']);
        $this->entityManager->flush();

        $rendered = $this->createLiveComponent('Shop', ['player' => $player])->render()->toString();

        $this->assertStringContainsString('data-advance-category="craft"', $rendered);
        $this->assertStringContainsString('data-advance-category="science"', $rendered);
    }

    #[Test]
    public function addTakesTheProductOutOfTheCatalogueAndUpdatesTheCartTotal(): void
    {
        $player = PlayerBuilder::named('Alice')->persist($this->entityManager);

        $component = $this->createLiveComponent('Shop', ['player' => $player]);
        $rendered = $component->call('add', ['key' => 'pottery'])->render()->toString();

        $this->assertStringNotContainsString('id="product-pottery"', $rendered);
        $this->assertStringContainsString('id="product-democracy"', $rendered);
        $this->assertStringContainsString('Total: 60', $rendered);
    }

    #[Test]
    public function addIsANoOpAndSetsAnErrorWhenTheCartIsLocked(): void
    {
        $player = PlayerBuilder::named('Alice')->persist($this->entityManager);
        OrderBuilder::for($player)->withLine(new OrderLine('pottery', 60))->validated(60)->persist($this->entityManager);

        $component = $this->createLiveComponent('Shop', ['player' => $player]);
        $crawler = $component->call('add', ['key' => 'democracy'])->render()->crawler();

        $this->assertCount(1, $crawler->filter('[role="alert"]'));
        $this->assertCount(1, $crawler->filter('.cart[data-status="validated"]'));
        $this->assertCount(0, $crawler->filter('[data-live-action-param="remove"]'));
    }

    #[Test]
    public function addingAnAlreadyOwnedAdvanceIsRefused(): void
    {
        $player = PlayerBuilder::named('Alice')->persist($this->entityManager);
        $player->ownAdvances(['agriculture']);
        $this->entityManager->flush();

        $component = $this->createLiveComponent('Shop', ['player' => $player]);
        $crawler = $component->call('add', ['key' => 'agriculture'])->render()->crawler();

        $this->assertCount(1, $crawler->filter('[role="alert"]'));
        $this->assertCount(1, $crawler->filter('li[data-empty]'));
    }

    #[Test]
    public function addingTheSameAdvanceTwiceIsDeduped(): void
    {
        $player = PlayerBuilder::named('Alice')->persist($this->entityManager);

        $component = $this->createLiveComponent('Shop', ['player' => $player]);
        $component->call('add', ['key' => 'pottery']);
        $rendered = $component->call('add', ['key' => 'pottery'])->render()->toString();

        $this->assertSame(1, substr_count($rendered, 'class="line"'));
        $this->assertStringContainsString('Total: 60', $rendered);
    }

    #[Test]
    public function submitOrderCreatesAPendingOrderAndEmptiesTheCart(): void
    {
        $player = PlayerBuilder::named('Alice')->persist($this->entityManager);
        $client = self::getContainer()->get('test.client');
        $this->seedCart($client, CartKey::shop($player), Cart::fromKeys(['pottery']));

        $this->createCart($player, $client)->call('checkout');

        $order = $this->freshOrderRepository()->findOneByPlayerAndWindow($player, $player->game->currentTurn);
        $this->assertInstanceOf(Order::class, $order);
        $this->assertSame(['pottery'], $order->keys());

        $rendered = $this->createLiveComponent('Shop', ['player' => $player])->render()->toString();
        $this->assertStringNotContainsString('data-live-action-param="remove"', $rendered);
        $this->assertStringContainsString('data-status="pending"', $rendered);
        $this->assertStringContainsString('Pottery', $rendered);
        $this->assertStringContainsString('data-live-action-param="editPendingOrder"', $rendered);
    }

    #[Test]
    public function aPendingOrderTurnsTheCartIntoAReadOnlyTicketCarryingModify(): void
    {
        $player = PlayerBuilder::named('Alice')->persist($this->entityManager);
        OrderBuilder::for($player)->withKeys('pottery')->persist($this->entityManager);

        $rendered = $this->createLiveComponent('Shop', ['player' => $player])->render()->toString();

        $this->assertStringNotContainsString('data-live-action-param="remove"', $rendered);
        $this->assertStringContainsString('data-status="pending"', $rendered);
        $this->assertStringContainsString('Pottery', $rendered);
        $this->assertStringContainsString('data-live-action-param="editPendingOrder"', $rendered);
    }

    #[Test]
    public function editPendingOrderReloadsItsLinesIntoTheCartAndHidesTheOrderBlock(): void
    {
        $player = PlayerBuilder::named('Alice')->persist($this->entityManager);
        $order = OrderBuilder::for($player)->withKeys('pottery', 'agriculture')->persist($this->entityManager);

        $component = $this->createLiveComponent('Shop', ['player' => $player]);
        $component->call('editPendingOrder');

        $rendered = $component->render()->toString();

        $this->assertStringContainsString('class="lines"', $rendered);
        $this->assertStringNotContainsString('id="product-pottery"', $rendered);
        $this->assertSame(2, substr_count($rendered, 'data-live-action-param="remove"'));
        $this->assertStringNotContainsString('data-live-action-param="editPendingOrder"', $rendered);
        $this->assertNotNull($order->id);
    }

    #[Test]
    #[DataProvider('provideTheCartTellsThePlayerWhereTheirOrderStandsCases')]
    public function theCartTellsThePlayerWhereTheirOrderStands(bool $withOrder, string $status, string $wording): void
    {
        $player = PlayerBuilder::named('Alice')->persist($this->entityManager);

        if ($withOrder) {
            OrderBuilder::for($player)->withKeys('pottery')->persist($this->entityManager);
        }

        $crawler = $this->createLiveComponent('Shop', ['player' => $player])->render()->crawler();

        $this->assertSame($status, $crawler->filter('.cart')->attr('data-status'));
        $this->assertStringContainsString($wording, $crawler->filter('.cart .hint')->text());
    }

    /** @return iterable<string, array{bool, string, string}> */
    public static function provideTheCartTellsThePlayerWhereTheirOrderStandsCases(): iterable
    {
        yield 'nothing submitted for the turn' => [false, 'missing', 'empty'];

        yield 'an order awaiting the operator' => [true, 'pending', 'submitted'];
    }

    #[Test]
    public function modifyingAPendingOrderWithdrawsItSoTheOperatorStopsSeeingIt(): void
    {
        $player = PlayerBuilder::named('Alice')->persist($this->entityManager);
        OrderBuilder::for($player)->withKeys('pottery', 'agriculture')->persist($this->entityManager);

        $this->createLiveComponent('Shop', ['player' => $player])->call('editPendingOrder');

        $this->entityManager->clear();

        $this->assertNotInstanceOf(
            Order::class,
            self::getContainer()->get(OrderRepository::class)
                ->findOneByPlayerAndWindow($player, $player->game->currentTurn),
        );
    }

    #[Test]
    public function aWithdrawnOrderGoesBackWithASingleResubmission(): void
    {
        $player = PlayerBuilder::named('Alice')->persist($this->entityManager);
        OrderBuilder::for($player)->withKeys('pottery', 'agriculture')->persist($this->entityManager);

        $client = self::getContainer()->get('test.client');
        $this->createLiveComponent('Shop', ['player' => $player], $client)->call('editPendingOrder');
        $this->createCart($player, $client)->call('checkout');

        $this->entityManager->clear();
        $order = self::getContainer()->get(OrderRepository::class)
            ->findOneByPlayerAndWindow($player, $player->game->currentTurn);

        $this->assertInstanceOf(Order::class, $order);
        $this->assertSame(['pottery', 'agriculture'], $order->keys());
        $this->assertSame(OrderStatus::Pending, $order->status);
    }

    #[Test]
    public function aValidatedOrderLocksTheKioskForTheTurn(): void
    {
        $player = PlayerBuilder::named('Alice')->persist($this->entityManager);
        OrderBuilder::for($player)->withLine(new OrderLine('pottery', 60))->validated(60)->persist($this->entityManager);

        $component = $this->createLiveComponent('Shop', ['player' => $player]);
        $this->assertTrue($component->component()->isLockedForTurn());

        $rendered = $component->render()->toString();
        $this->assertStringNotContainsString('data-live-action-param="remove"', $rendered);
        $this->assertStringNotContainsString('data-live-action-param="editPendingOrder"', $rendered);
        $this->assertStringContainsString('data-status="validated"', $rendered);
        $this->assertStringContainsString('Pottery', $rendered);
    }

    #[Test]
    public function aValidatedOrderWithLeftoverCartItemsKeepsSubmitDisabled(): void
    {
        $player = PlayerBuilder::named('Alice')->persist($this->entityManager);
        OrderBuilder::for($player)->withLine(new OrderLine('pottery', 60))->validated(60)->persist($this->entityManager);

        $client = self::getContainer()->get('test.client');
        $this->seedCart($client, CartKey::shop($player), Cart::fromKeys(['democracy']));

        $crawler = $this->createLiveComponent('Shop', ['player' => $player], $client)->render()->crawler();

        $this->assertStringContainsString('Democracy', $crawler->filter('.lines')->text());
        $this->assertTrue($crawler->filter('[data-live-action-param="checkout"]')->getNode(0)->hasAttribute('disabled'));
    }

    #[Test]
    public function validatedOrderShowsFrozenPricesRatherThanRecomputedOnes(): void
    {
        $player = PlayerBuilder::named('Alice')->persist($this->entityManager);
        OrderBuilder::for($player)->withLine(new OrderLine('pottery', 999))->validated(999)->persist($this->entityManager);

        $rendered = $this->createLiveComponent('Shop', ['player' => $player])->render()->toString();

        $this->assertStringContainsString('999', $rendered);
        $this->assertStringNotContainsString('data-live-action-param="editPendingOrder"', $rendered);
        $this->assertStringNotContainsString('data-live-action-param="remove"', $rendered);
    }

    #[Test]
    public function anEmptiedBudgetFieldIsNoConstraintWhereABudgetOfZeroIsOne(): void
    {
        $player = PlayerBuilder::named('Alice')->persist($this->entityManager);

        $emptied = $this->createLiveComponent('Shop', ['player' => $player])->set('budget', '');
        $zeroed = $this->createLiveComponent('Shop', ['player' => $player])->set('budget', 0);

        $this->assertNull($emptied->component()->budget);
        $this->assertSame('', $emptied->render()->crawler()->filter('output[for="shop-budget"]')->text());
        $this->assertFalse($emptied->render()->crawler()->filter('#product-mysticism')->getNode(0)->hasAttribute('disabled'));

        $this->assertSame(0, $zeroed->component()->budget);
        $this->assertSame('Remaining: 0', $zeroed->render()->crawler()->filter('output[for="shop-budget"]')->text());
        $this->assertTrue($zeroed->render()->crawler()->filter('#product-mysticism')->getNode(0)->hasAttribute('disabled'));
    }

    #[Test]
    public function theBandStaysBlankUntilABudgetIsEnteredAndThenShowsWhatIsLeft(): void
    {
        $player = PlayerBuilder::named('Alice')->persist($this->entityManager);

        $withoutBudget = $this->createLiveComponent('Shop', ['player' => $player])->render()->crawler();
        $withBudget = $this->createLiveComponent('Shop', ['player' => $player, 'budget' => 110])->render()->crawler();

        $this->assertCount(1, $withoutBudget->filter('input#shop-budget'));
        $this->assertSame('', $withoutBudget->filter('output[for="shop-budget"]')->text());
        $this->assertSame('Remaining: 110', $withBudget->filter('output[for="shop-budget"]')->text());
    }

    #[Test]
    public function addingToTheCartDrawsTheRemainderDownAndDisablesWhatItNoLongerAffords(): void
    {
        $player = PlayerBuilder::named('Alice')->persist($this->entityManager);

        $component = $this->createLiveComponent('Shop', ['player' => $player, 'budget' => 110]);
        $beforeTheAdd = $component->render()->crawler();
        $afterTheAdd = $component->call('add', ['key' => 'pottery'])->render()->crawler();

        $this->assertFalse($beforeTheAdd->filter('#product-empiricism')->getNode(0)->hasAttribute('disabled'));
        $this->assertSame('Remaining: 50', $afterTheAdd->filter('output[for="shop-budget"]')->text());
        $this->assertTrue($afterTheAdd->filter('#product-empiricism')->getNode(0)->hasAttribute('disabled'));
        $this->assertFalse($afterTheAdd->filter('#product-mysticism')->getNode(0)->hasAttribute('disabled'));
    }

    #[Test]
    public function removingFromTheCartRestoresTheRemainderAndReEnablesTheTilesItHadDisabled(): void
    {
        $player = PlayerBuilder::named('Alice')->persist($this->entityManager);
        $client = self::getContainer()->get('test.client');
        $this->seedCart($client, CartKey::shop($player), Cart::fromKeys(['pottery']));

        $withPottery = $this->createLiveComponent('Shop', ['player' => $player, 'budget' => 110], $client)->render()->crawler();
        $this->createCart($player, $client)->call('remove', ['key' => 'pottery']);
        $withoutPottery = $this->createLiveComponent('Shop', ['player' => $player, 'budget' => 110], $client)->render()->crawler();

        $this->assertTrue($withPottery->filter('#product-empiricism')->getNode(0)->hasAttribute('disabled'));
        $this->assertSame('Remaining: 110', $withoutPottery->filter('output[for="shop-budget"]')->text());
        $this->assertFalse($withoutPottery->filter('#product-empiricism')->getNode(0)->hasAttribute('disabled'));
    }

    #[Test]
    public function aRemainderGoneNegativeIsShownAsZeroWhileEveryTileStaysDisabled(): void
    {
        $player = PlayerBuilder::named('Alice')->persist($this->entityManager);
        $client = self::getContainer()->get('test.client');
        $this->seedCart($client, CartKey::shop($player), Cart::fromKeys(['pottery']));

        $crawler = $this->createLiveComponent('Shop', ['player' => $player, 'budget' => 5], $client)->render()->crawler();

        $this->assertSame('Remaining: 0', $crawler->filter('output[for="shop-budget"]')->text());
        $this->assertCount(50, $crawler->filter('button[id^="product-"]'));
        $this->assertCount(50, $crawler->filter('button[id^="product-"][disabled]'));
    }

    #[Test]
    public function aBudgetFarSmallerThanTheOrderStillLetsThePlayerSubmitIt(): void
    {
        $player = PlayerBuilder::named('Alice')->persist($this->entityManager);
        $client = self::getContainer()->get('test.client');
        $this->seedCart($client, CartKey::shop($player), Cart::fromKeys(['pottery', 'democracy']));

        $crawler = $this->createLiveComponent('Shop', ['player' => $player, 'budget' => 5], $client)->render()->crawler();
        $this->assertSame('Remaining: 0', $crawler->filter('output[for="shop-budget"]')->text());
        $this->assertFalse($crawler->filter('[data-live-action-param="checkout"]')->getNode(0)->hasAttribute('disabled'));

        $this->createCart($player, $client)->call('checkout');

        $order = $this->freshOrderRepository()->findOneByPlayerAndWindow($player, $player->game->currentTurn);
        $this->assertInstanceOf(Order::class, $order);
        $this->assertSame(['pottery', 'democracy'], $order->keys());
        $this->assertSame(OrderStatus::Pending, $order->status);
    }

    #[Test]
    public function theBudgetBandIsTheKiosksAloneAndNeverReachesTheOperatorsPos(): void
    {
        $player = PlayerBuilder::named('Alice')->persist($this->entityManager);

        $kiosk = $this->createLiveComponent('Shop', ['player' => $player, 'budget' => 5])->render()->crawler();
        $pos = $this->openPos($player);

        $this->assertCount(1, $kiosk->filter('input#shop-budget'));
        $this->assertCount(0, $pos->filter('input#shop-budget'));
        $this->assertCount(0, $pos->filter('output[for="shop-budget"]'));

        $this->assertTrue($kiosk->filter('#product-pottery')->getNode(0)->hasAttribute('disabled'));
        $this->assertFalse($pos->filter('#product-pottery')->getNode(0)->hasAttribute('disabled'));
    }

    #[Test]
    public function theKioskOffersThreeSortsWithBestValuePreselected(): void
    {
        $player = PlayerBuilder::named('Alice')->persist($this->entityManager);

        $crawler = $this->createLiveComponent('Shop', ['player' => $player])->render()->crawler();

        $this->assertCount(3, $crawler->filter('input[name="sort"]'));
        $this->assertCount(1, $crawler->filter('input[name="sort"][checked]'));
        $this->assertCount(1, $crawler->filter('input[name="sort"][value="net_price"][checked]'));
    }

    #[Test]
    public function aShopMountedSortedByNameShelvesTheAdvancesAlphabetically(): void
    {
        $player = $this->discountedPlayer();

        $byBestValue = $this->createLiveComponent('Shop', ['player' => $player])->render()->crawler();
        $byName = $this->createLiveComponent('Shop', ['player' => $player, 'sort' => CatalogSort::Name])->render()->crawler();

        $names = $this->shelfNames($byName);
        $alphabetical = $names;
        sort($alphabetical);

        $this->assertCount(1, $byName->filter('input[name="sort"][value="name"][checked]'));
        $this->assertSame($alphabetical, $names);
        $this->assertNotSame($this->shelfKeys($byBestValue), $this->shelfKeys($byName));
    }

    #[Test]
    public function choosingNameOnTheShelfReordersItAlphabetically(): void
    {
        $player = $this->discountedPlayer();

        $component = $this->createLiveComponent('Shop', ['player' => $player]);
        $byBestValue = $component->render()->crawler();
        $byName = $component->set('sort', 'name')->render()->crawler();

        $names = $this->shelfNames($byName);
        $alphabetical = $names;
        sort($alphabetical);

        $this->assertCount(1, $byName->filter('input[name="sort"][value="name"][checked]'));
        $this->assertSame($alphabetical, $names);
        $this->assertNotSame($this->shelfKeys($byBestValue), $this->shelfKeys($byName));
    }

    #[Test]
    public function theSortIsTheKiosksAloneAndNeverReachesTheOperatorsPos(): void
    {
        $player = PlayerBuilder::named('Alice')->persist($this->entityManager);

        $pos = $this->openPos($player);

        $this->assertCount(0, $pos->filter('input[name="sort"]'));
    }

    #[Test]
    public function validatingTheTurnsOrderShutsTheKioskShelfThatWasOpenBefore(): void
    {
        $player = PlayerBuilder::named('Alice')->persist($this->entityManager);

        $open = $this->createLiveComponent('Shop', ['player' => $player])->render()->crawler();

        OrderBuilder::for($player)->withLine(new OrderLine('pottery', 60))->validated(60)->persist($this->entityManager);

        $shut = $this->createLiveComponent('Shop', ['player' => $player])->render()->crawler();

        $this->assertCount(51, $open->filter('button[id^="product-"]'));
        $this->assertCount(0, $open->filter('button[id^="product-"][disabled]'));
        $this->assertCount(50, $shut->filter('button[id^="product-"]'));
        $this->assertCount(50, $shut->filter('button[id^="product-"][disabled]'));
    }

    #[Test]
    public function theShopListensOnItsOwnTopic(): void
    {
        $player = PlayerBuilder::named('Alice')->persist($this->entityManager);

        $rendered = $this->createLiveComponent('Shop', ['player' => $player])->render()->crawler();

        $this->assertSame(
            'empires/game/'.$player->game->id.'/player/'.$player->id.'/shop',
            $rendered->filter('[data-mercure-refresh-topic-value]')->attr('data-mercure-refresh-topic-value'),
        );
    }

    #[Test]
    public function getPlayerShopReturnsTwoHundredWithLiveAndMercureWiring(): void
    {
        $player = PlayerBuilder::named('Alice')->persist($this->entityManager);

        $client = self::getClient(self::getContainer()->get('test.client'));
        $client->request('GET', '/'.$player->game->slug.'/player/'.$player->slug.'/shop');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('[data-controller~="live"]');

        $html = (string) $client->getResponse()->getContent();
        $this->assertStringContainsString('empires/game/'.$player->game->id, $html);
    }

    #[Test]
    public function editPendingOrderReloadsTheGiftChoiceIntoTheCart(): void
    {
        $player = PlayerBuilder::named('Alice')->persist($this->entityManager);
        $client = self::getContainer()->get('test.client');
        $cart = Cart::fromKeys(['anatomy']);
        $cart->withGift('anatomy', 'astronavigation');
        $this->seedCart($client, CartKey::shop($player), $cart);

        $this->createCart($player, $client)->call('checkout');

        $freshComponent = $this->createLiveComponent('Shop', ['player' => $player]);
        $freshComponent->call('editPendingOrder');

        $rendered = $freshComponent->render()->toString();

        $this->assertStringContainsString('Free gift: Astronavigation', $rendered);
    }

    #[Test]
    public function editPendingOrderReloadsTheAllocationIntoTheCart(): void
    {
        $player = PlayerBuilder::named('Alice')->persist($this->entityManager);
        $client = self::getContainer()->get('test.client');
        $cart = Cart::fromKeys(['monument']);
        $cart->withAllocation('monument', 'craft', 10);
        $cart->withAllocation('monument', 'science', 10);
        $this->seedCart($client, CartKey::shop($player), $cart);

        $this->createCart($player, $client)->call('checkout');

        $freshComponent = $this->createLiveComponent('Shop', ['player' => $player]);
        $freshComponent->call('editPendingOrder');

        $crawler = $freshComponent->render()->crawler();

        $this->assertStringContainsString('Remaining: 0', $crawler->filter('.allocation-picker')->text());
        $this->assertSame(['0', '0', '10', '0', '10'], $crawler->filter('.allocation-picker .value')->each(static fn ($node) => $node->text()));
        $this->assertFalse($crawler->filter('[data-live-action-param="checkout"]')->getNode(0)->hasAttribute('disabled'));
    }

    private function createCart(Player $player, KernelBrowser $client): TestLiveComponent
    {
        return $this->createLiveComponent('Cart', [
            'player' => $player,
            'storageKey' => CartKey::shop($player),
        ], $client);
    }

    /** The till reads its cart at mount, so unlike the kiosk it cannot be built outside a request with a session. */
    private function openPos(Player $player): Crawler
    {
        return $this->createLiveComponent('CashierTerminal', [
            'game' => $player->game,
            'turn' => $player->game->currentTurn,
            'playerSlug' => $player->slug,
        ], self::getContainer()->get('test.client'))->render()->crawler();
    }

    /** @return list<string> */
    private function shelfKeys(Crawler $crawler): array
    {
        return $crawler->filter('button[id^="product-"]')->each(
            static fn (Crawler $node): string => substr((string) $node->attr('id'), \strlen('product-')),
        );
    }

    /** @return list<string> */
    private function shelfNames(Crawler $crawler): array
    {
        return $crawler->filter('.product-tile .name')->each(static fn (Crawler $node): string => $node->text());
    }

    private function discountedPlayer(): Player
    {
        $player = PlayerBuilder::named('Alice')->persist($this->entityManager);
        self::getContainer()->get(AdvanceFulfillment::class)->grant($player->id, ['agriculture'], $player->game->currentTurn);
        $this->entityManager->flush();

        return $player;
    }

    private function freshOrderRepository(): OrderRepository
    {
        return self::getContainer()->get(OrderRepository::class);
    }
}
