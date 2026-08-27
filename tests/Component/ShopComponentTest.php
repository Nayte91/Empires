<?php

declare(strict_types=1);

namespace App\Tests\Component;

use App\Engine\Shop\AdvanceFulfillment;
use App\Rules\Ruleset\Advance;
use App\Rules\Ruleset\AdvanceRegistry;
use App\State\Order;
use App\State\Player;
use App\Infrastructure\Repository\OrderRepository;
use App\Tests\Support\Fixture\PlayerBuilder;
use App\Tests\Support\GameFixtureTrait;
use Symfony\Component\DomCrawler\Crawler;
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
        $order = $this->createPendingOrder($player, 'pottery');
        $order->freeze([new OrderLine('pottery', 60)], 60);
        $order->setMarking(OrderStatus::Validated->value);
        $this->entityManager->flush();

        $component = $this->createLiveComponent('Shop', ['player' => $player]);
        $crawler = $component->call('add', ['key' => 'democracy'])->render()->crawler();

        $this->assertCount(1, $crawler->filter('[role="alert"]'));
        $this->assertCount(1, $crawler->filter('.shop__order[data-status="validated"]'));
        $this->assertCount(0, $crawler->filter('.lines'));
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
        $this->cartFor($client, $player, Cart::fromKeys(['pottery']));

        $this->createCart($player, $client)->call('checkout');

        $order = $this->freshOrderRepository()->findOneByPlayerAndWindow($player, $player->game->currentTurn);
        $this->assertInstanceOf(Order::class, $order);
        $this->assertSame(['pottery'], $order->keys());

        $rendered = $this->createLiveComponent('Shop', ['player' => $player])->render()->toString();
        $this->assertStringNotContainsString('class="lines"', $rendered);
        $this->assertStringContainsString('data-status="pending"', $rendered);
        $this->assertStringContainsString('Pottery', $rendered);
        $this->assertStringContainsString('data-live-action-param="editPendingOrder"', $rendered);
    }

    #[Test]
    public function pendingOrderHidesTheCartAndShowsTheOrderBlockWithModify(): void
    {
        $player = PlayerBuilder::named('Alice')->persist($this->entityManager);
        $this->createPendingOrder($player, 'pottery');

        $rendered = $this->createLiveComponent('Shop', ['player' => $player])->render()->toString();

        $this->assertStringNotContainsString('class="lines"', $rendered);
        $this->assertStringContainsString('data-status="pending"', $rendered);
        $this->assertStringContainsString('Pottery', $rendered);
        $this->assertStringContainsString('data-live-action-param="editPendingOrder"', $rendered);
    }

    #[Test]
    public function editPendingOrderReloadsItsLinesIntoTheCartAndHidesTheOrderBlock(): void
    {
        $player = PlayerBuilder::named('Alice')->persist($this->entityManager);
        $order = $this->createPendingOrder($player, 'pottery', 'agriculture');

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
        $this->assertStringNotContainsString('class="lines"', $rendered);
        $this->assertStringNotContainsString('data-live-action-param="editPendingOrder"', $rendered);
        $this->assertStringContainsString('data-status="validated"', $rendered);
        $this->assertStringContainsString('Pottery', $rendered);
    }

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

        $this->assertStringContainsString('Democracy', $crawler->filter('.lines')->text());
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
        $this->assertStringNotContainsString('data-live-action-param="editPendingOrder"', $rendered);
        $this->assertStringNotContainsString('class="lines"', $rendered);
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

        $this->assertStringContainsString('data-status="rejected"', $rendered);

        $component->call('editPendingOrder');
        $editedRendered = $component->render()->toString();
        $this->assertStringContainsString('class="lines"', $editedRendered);
        $this->assertStringNotContainsString('id="product-pottery"', $editedRendered);

        $this->createCart($player, $client)->call('checkout');

        $reloadedOrder = $this->freshOrderRepository()->findOneByPlayerAndWindow($player, $player->game->currentTurn);
        $this->assertInstanceOf(Order::class, $reloadedOrder);
        $this->assertSame(OrderStatus::Pending, $reloadedOrder->status);
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
        $this->cartFor($client, $player, Cart::fromKeys(['pottery']));

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
        $this->cartFor($client, $player, Cart::fromKeys(['pottery']));

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
        $this->cartFor($client, $player, Cart::fromKeys(['pottery', 'democracy']));

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
    public function theKioskShelfIsOrderedByWhatThePlayerPaysWhereTheOperatorsPosKeepsTheRegistryOrder(): void
    {
        $player = PlayerBuilder::named('Alice')->persist($this->entityManager);
        self::getContainer()->get(AdvanceFulfillment::class)->grant($player->id, ['agriculture']);
        $this->entityManager->flush();

        $kiosk = $this->createLiveComponent('Shop', ['player' => $player])->render()->crawler();
        $pos = $this->openPos($player);

        $kioskNetCosts = $kiosk->filter('[data-price-net]')->each(static fn (Crawler $node): int => (int) $node->text());
        $ascendingNetCosts = $kioskNetCosts;
        sort($ascendingNetCosts);

        $this->assertSame($ascendingNetCosts, $kioskNetCosts);
        $this->assertNotSame($this->shelfKeys($kiosk), $this->shelfKeys($pos));
        $this->assertSame($this->registryOrderOf($this->shelfKeys($pos)), $this->shelfKeys($pos));
    }

    #[Test]
    public function validatingTheTurnsOrderShutsTheKioskShelfThatWasOpenBefore(): void
    {
        $player = PlayerBuilder::named('Alice')->persist($this->entityManager);

        $open = $this->createLiveComponent('Shop', ['player' => $player])->render()->crawler();

        $order = $this->createPendingOrder($player, 'pottery');
        $order->freeze([new OrderLine('pottery', 60)], 60);
        $order->setMarking(OrderStatus::Validated->value);
        $this->entityManager->flush();

        $shut = $this->createLiveComponent('Shop', ['player' => $player])->render()->crawler();

        $this->assertCount(51, $open->filter('button[id^="product-"]'));
        $this->assertCount(0, $open->filter('button[id^="product-"][disabled]'));
        $this->assertCount(51, $shut->filter('button[id^="product-"]'));
        $this->assertCount(51, $shut->filter('button[id^="product-"][disabled]'));
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
        $this->assertSame(['0', '0', '10', '0', '10'], $crawler->filter('.allocation-picker .value')->each(static fn ($node) => $node->text()));
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

    private function createCart(Player $player, KernelBrowser $client): TestLiveComponent
    {
        return $this->createLiveComponent('Cart', [
            'player' => $player,
            'storageKey' => (string) $player->id,
        ], $client);
    }

    private function openPos(Player $player): Crawler
    {
        return $this->createLiveComponent('PlayerOrders', [
            'player' => $player,
            'ordersStamp' => '',
            'posOpen' => true,
            'posTurn' => $player->game->currentTurn,
        ])->render()->crawler();
    }

    /** @return list<string> */
    private function shelfKeys(Crawler $crawler): array
    {
        return $crawler->filter('button[id^="product-"]')->each(
            static fn (Crawler $node): string => substr((string) $node->attr('id'), \strlen('product-')),
        );
    }

    /**
     * @param list<string> $keys
     *
     * @return list<string>
     */
    private function registryOrderOf(array $keys): array
    {
        return array_map(
                static fn(Advance $advance): string => $advance->key,
                self::getContainer()->get(AdvanceRegistry::class)->getAdvances(),
            )
                |> (fn($x): array => array_filter($x, static fn(string $key): bool => \in_array($key, $keys, true),))
                |> array_values(...);
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
        return $component->component();
    }
}
