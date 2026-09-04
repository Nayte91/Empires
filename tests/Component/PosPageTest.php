<?php

declare(strict_types=1);

namespace App\Tests\Component;

use App\Infrastructure\Repository\OrderRepository;
use App\Presentation\Shop\CartKey;
use App\State\Game;
use App\State\Order;
use App\State\Player;
use App\Tests\Support\Fixture\OrderBuilder;
use App\Tests\Support\Fixture\Tables;
use App\Tests\Support\GameFixtureTrait;
use App\Tests\Support\ShopFixtureTrait;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\UX\LiveComponent\Test\InteractsWithLiveComponents;
use Symfony\UX\LiveComponent\Test\TestLiveComponent;
use Userforged\ShopEngine\Cart;
use Userforged\ShopEngine\Dto\OrderLine;
use Userforged\ShopEngine\OrderStatus;
use Userforged\ShopEngine\Promotion\AppliedPromotion;
use Userforged\ShopEngine\Promotion\PromotionType;
use Userforged\ShopEngine\Service\OrderValidator;

final class PosPageTest extends WebTestCase
{
    use GameFixtureTrait;
    use InteractsWithLiveComponents;
    use ShopFixtureTrait;

    #[Test]
    public function withNoBuyerChosenTheTillShowsOnlyItsBuyerSelect(): void
    {
        [$game] = Tables::aliceAndBob($this->entityManager);

        $component = $this->createPos($game);
        $crawler = $component->render()->crawler();

        $this->assertNull($component->component()->getPlayer());
        $this->assertCount(1, $crawler->filter('select[data-model="playerSlug"]'));
        $this->assertCount(0, $crawler->filter('[data-live-action-param="checkout"]'));
        $this->assertCount(0, $crawler->filter('button[id^="product-"]'));
        $this->assertCount(0, $crawler->filter('table'));
    }

    #[Test]
    #[DataProvider('provideASlugMatchingNobodyIsTreatedAsNoBuyerChosenCases')]
    public function aSlugMatchingNobodyIsTreatedAsNoBuyerChosen(string $slug): void
    {
        [$game] = Tables::aliceAndBob($this->entityManager);

        $component = $this->createPos($game)->set('playerSlug', $slug);
        $crawler = $component->render()->crawler();

        $this->assertNull($component->component()->getPlayer());
        $this->assertCount(0, $crawler->filter('[data-live-action-param="checkout"]'));
        $this->assertCount(0, $crawler->filter('button[id^="product-"]'));
    }

    #[Test]
    public function clearingTheTillAlsoWithdrawsTheOrderBehindIt(): void
    {
        [, , $bob] = Tables::aliceAndBob($this->entityManager);
        OrderBuilder::for($bob)->withKeys('pottery')->persist($this->entityManager);

        $this->createPos($bob->game)->set('playerSlug', $bob->slug)->call('onCartCleared');

        $this->entityManager->clear();

        $this->assertNotInstanceOf(
            Order::class,
            self::getContainer()->get(OrderRepository::class)
                ->findOneByPlayerAndWindow($bob, $bob->game->currentTurn),
        );
    }

    /** @return iterable<string, array{string}> */
    public static function provideASlugMatchingNobodyIsTreatedAsNoBuyerChosenCases(): iterable
    {
        yield 'the placeholder option hands back an empty string' => [''];

        yield 'a slug naming nobody in this game' => ['carthage'];
    }

    #[Test]
    public function choosingABuyerPreloadsTheirPendingOrderIntoTheTill(): void
    {
        [, , $bob] = Tables::aliceAndBob($this->entityManager);
        OrderBuilder::for($bob)->withKeys('pottery')->persist($this->entityManager);

        $crawler = $this->createPos($bob->game)->set('playerSlug', $bob->slug)->render()->crawler();

        $this->assertFalse($crawler->filter('[data-live-action-param="checkout"]')->getNode(0)->hasAttribute('disabled'));
        $this->assertCount(0, $crawler->filter('#product-pottery'));
    }

    #[Test]
    public function choosingABuyerWhoseTillAlreadyHoldsItemsKeepsWhatTheOperatorBuilt(): void
    {
        [$game, , $bob] = Tables::aliceAndBob($this->entityManager);
        $client = self::getContainer()->get('test.client');
        OrderBuilder::for($bob)->withKeys('pottery')->persist($this->entityManager);
        $this->seedCart($client, CartKey::pos($bob, $game->currentTurn), Cart::fromKeys(['democracy']));

        $crawler = $this->createPos($game, client: $client)->set('playerSlug', $bob->slug)->render()->crawler();

        $this->assertCount(0, $crawler->filter('#product-democracy'));
        $this->assertCount(1, $crawler->filter('#product-pottery'));
    }

    #[Test]
    public function choosingABuyerWithNothingSubmittedForTheTurnOpensAnEmptyTill(): void
    {
        [, , $bob] = Tables::aliceAndBob($this->entityManager);

        $crawler = $this->createPos($bob->game)->set('playerSlug', $bob->slug)->render()->crawler();

        $this->assertTrue($crawler->filter('[data-live-action-param="checkout"]')->getNode(0)->hasAttribute('disabled'));
    }

    #[Test]
    public function aValidatedTurnShowsItsReceiptAndNoWayToBuyMore(): void
    {
        [, , $bob] = Tables::aliceAndBob($this->entityManager);
        $order = $this->validateOrderFor($bob, ['pottery']);

        $crawler = $this->createPos($bob->game)->set('playerSlug', $bob->slug)->render()->crawler();

        $this->assertStringContainsString('validated', $crawler->filter('.hint')->text());
        $this->assertStringContainsString('Pottery', $crawler->filter('.lines')->text());
        $this->assertSame('Total: '.$order->total, trim($crawler->filter('.total')->text()));

        $this->assertCount(0, $crawler->filter('[data-live-action-param="checkout"]'));
        $this->assertCount(0, $crawler->filter('button[id^="product-"]'));
    }

    #[Test]
    public function aValidatedTurnOffersTheCascadingEraseBehindItsConfirmation(): void
    {
        [$game, , $bob] = Tables::aliceAndBob($this->entityManager);
        $game->currentTurn = 3;
        $this->entityManager->flush();
        $this->validateOrderFor($bob, ['pottery']);

        $crawler = $this->createPos($game, turn: 3)->set('playerSlug', $bob->slug)->render()->crawler();

        $this->assertCount(1, $crawler->filter('button[commandfor^="erase-confirm-"]'));
        $this->assertCount(1, $crawler->filter('dialog[id^="erase-confirm-"] [data-live-action-param="eraseOrder"]'));
    }

    #[Test]
    public function erasingFromTheTillRemovesTheOrderAndDisownsItsAdvances(): void
    {
        [, , $bob] = Tables::aliceAndBob($this->entityManager);
        $this->validateOrderFor($bob, ['pottery']);

        $this->createPos($bob->game)->set('playerSlug', $bob->slug)->call('eraseOrder');

        $this->assertNotInstanceOf(
            Order::class,
            self::getContainer()->get(OrderRepository::class)->findOneByPlayerAndWindow($bob, $bob->game->currentTurn),
        );

        $this->assertNotContains('pottery', $this->reloadPlayer($bob)->advances);
    }

    #[Test]
    public function addTakesTheProductOutOfTheCatalogueAndUpdatesTheTicketTotal(): void
    {
        [, , $bob] = Tables::aliceAndBob($this->entityManager);

        $rendered = $this->createPos($bob->game)
            ->set('playerSlug', $bob->slug)
            ->call('add', ['key' => 'pottery'])
            ->render()
            ->toString();

        $this->assertStringNotContainsString('id="product-pottery"', $rendered);
        $this->assertStringContainsString('id="product-democracy"', $rendered);
        $this->assertStringContainsString('Total: 60', $rendered);
    }

    #[Test]
    public function aRefusedAddSurfacesItsErrorOnTheTillPage(): void
    {
        [, $alice] = Tables::aliceAndBob($this->entityManager);

        $crawler = $this->createPos($alice->game)
            ->set('playerSlug', $alice->slug)
            ->call('add', ['key' => 'agriculture'])
            ->render()
            ->crawler();

        $alert = $crawler->filter('main p[role="alert"]');
        $this->assertCount(1, $alert);
        $this->assertNotSame('', trim($alert->text()));
    }

    #[Test]
    public function checkoutValidatesTheTillsTurnOrderAndOwnsTheAdvances(): void
    {
        [$game, , $bob] = Tables::aliceAndBob($this->entityManager);
        $client = self::getContainer()->get('test.client');

        $this->seedCart($client, CartKey::pos($bob, $game->currentTurn), Cart::fromKeys(['pottery', 'democracy']));

        $this->createPosCart($bob, $game->currentTurn, $client)->call('checkout');

        $order = $this->freshOrderRepository()->findOneByPlayerAndWindow($bob, $game->currentTurn);
        $this->assertInstanceOf(Order::class, $order);
        $this->assertSame(OrderStatus::Validated, $order->status);
        $this->assertSame(['pottery', 'democracy'], $this->reloadPlayer($bob)->advances);
    }

    /** @param list<string> $expectedAdvances */
    #[Test]
    #[DataProvider('provideCheckoutAtTheTillValidatesAPromotedOrderImmediatelyCases')]
    public function checkoutAtTheTillValidatesAPromotedOrderImmediately(Cart $ticket, array $expectedAdvances): void
    {
        [$game, , $bob] = Tables::aliceAndBob($this->entityManager);
        $client = self::getContainer()->get('test.client');

        $this->seedCart($client, CartKey::pos($bob, $game->currentTurn), $ticket);

        $this->createPosCart($bob, $game->currentTurn, $client)->call('checkout');

        $order = $this->freshOrderRepository()->findOneByPlayerAndWindow($bob, $game->currentTurn);
        $this->assertInstanceOf(Order::class, $order);
        $this->assertSame(OrderStatus::Validated, $order->status);
        $this->assertSame($expectedAdvances, $this->reloadPlayer($bob)->advances);
    }

    /** @return iterable<string, array{Cart, list<string>}> */
    public static function provideCheckoutAtTheTillValidatesAPromotedOrderImmediatelyCases(): iterable
    {
        $gifted = Cart::fromKeys(['anatomy']);
        $gifted->withGift('anatomy', 'astronavigation');

        yield 'a gift chosen at the till' => [$gifted, ['anatomy', 'astronavigation']];

        $allocated = Cart::fromKeys(['monument']);
        $allocated->withAllocation('monument', 'craft', 10);
        $allocated->withAllocation('monument', 'science', 10);

        yield 'an option pool fully allocated at the till' => [$allocated, ['monument']];
    }

    #[Test]
    public function checkoutWithAPartialAllocationIsRejectedServerSideAndShowsAnError(): void
    {
        [$game, , $bob] = Tables::aliceAndBob($this->entityManager);
        $client = self::getContainer()->get('test.client');

        $ticket = Cart::fromKeys(['monument']);
        $ticket->withAllocation('monument', 'science', 5);
        $this->seedCart($client, CartKey::pos($bob, $game->currentTurn), $ticket);

        $rendered = $this->createPosCart($bob, $game->currentTurn, $client)->call('checkout')->render()->toString();

        $this->assertNotInstanceOf(Order::class, $this->freshOrderRepository()->findOneByPlayerAndWindow($bob, $game->currentTurn));
        $this->assertStringContainsString('Finish allocating the bonus for', $rendered);
        $this->assertStringContainsString('monument', $rendered);
    }

    #[Test]
    public function choosingABuyerWithAKioskSubmittedMonumentOrderReloadsTheAllocationIntoTheTill(): void
    {
        [$game, , $bob] = Tables::aliceAndBob($this->entityManager);

        OrderBuilder::for($bob)
            ->withLine(new OrderLine('monument', 180, new AppliedPromotion(PromotionType::Option, 'monument', allocation: ['craft' => 10, 'science' => 10])))
            ->persist($this->entityManager)
        ;

        $crawler = $this->createPos($game)->set('playerSlug', $bob->slug)->render()->crawler();

        $picker = $crawler->filter('.allocation-picker');
        $this->assertStringContainsString('Remaining: 0', $picker->text());
        $this->assertSame(
            ['craft' => '10', 'science' => '10'],
            array_filter($this->allocationOf($picker), static fn (string $points): bool => '0' !== $points),
        );
    }

    #[Test]
    public function checkoutOnAPastTurnValidatesThatTurnsOrderNotTheCurrentOne(): void
    {
        [$game, , $bob] = Tables::aliceAndBob($this->entityManager);
        $game->currentTurn = 3;
        $this->entityManager->flush();
        $client = self::getContainer()->get('test.client');

        $this->seedCart($client, CartKey::pos($bob, 1), Cart::fromKeys(['pottery']));
        $this->createPosCart($bob, 1, $client)->call('checkout');

        $pastOrder = $this->freshOrderRepository()->findOneByPlayerAndWindow($bob, 1);
        $this->assertInstanceOf(Order::class, $pastOrder);
        $this->assertSame(OrderStatus::Validated, $pastOrder->status);

        $this->assertNotInstanceOf(Order::class, $this->freshOrderRepository()->findOneByPlayerAndWindow($bob, 3));
    }

    #[Test]
    public function theTillPreloadsTheOrderOfTheTurnItIsPointedAtNotTheCurrentOne(): void
    {
        [$game, , $bob] = Tables::aliceAndBob($this->entityManager);
        OrderBuilder::for($bob)->withKeys('pottery')->persist($this->entityManager);
        $game->currentTurn = 3;
        $this->entityManager->flush();

        $crawler = $this->createPos($game, turn: 1)->set('playerSlug', $bob->slug)->render()->crawler();

        $this->assertCount(0, $crawler->filter('#product-pottery'));
        $this->assertTrue(
            $this->createPos($game, turn: 3)->set('playerSlug', $bob->slug)->render()->crawler()
                ->filter('[data-live-action-param="checkout"]')->getNode(0)->hasAttribute('disabled'),
        );
    }

    #[Test]
    public function checkoutOnAnAlreadyValidatedTurnShowsADomainExceptionMessage(): void
    {
        [$game, $alice] = Tables::aliceAndBob($this->entityManager);
        $this->validateOrderFor($alice, ['democracy']);
        $client = self::getContainer()->get('test.client');

        $this->seedCart($client, CartKey::pos($alice, $game->currentTurn), Cart::fromKeys(['pottery']));
        $crawler = $this->createPosCart($alice, $game->currentTurn, $client)->call('checkout')->render()->crawler();

        $alert = $crawler->filter('p[role="alert"]');
        $this->assertCount(1, $alert);
        $this->assertNotSame('', trim($alert->text()));
    }

    private function createPos(Game $game, ?int $turn = null, ?KernelBrowser $client = null): TestLiveComponent
    {
        return $this->createLiveComponent('CashierTerminal', ['game' => $game, 'turn' => $turn ?? $game->currentTurn], $client);
    }

    private function createPosCart(Player $player, int $turn, KernelBrowser $client): TestLiveComponent
    {
        return $this->createLiveComponent('Cart', [
            'player' => $player,
            'storageKey' => CartKey::pos($player, $turn),
            'directSale' => true,
            'window' => $turn,
        ], $client);
    }

    /** @return array<string, string> */
    private function allocationOf(Crawler $picker): array
    {
        $allocation = [];

        foreach ($picker->filter('li[data-advance-category]') as $row) {
            $allocation[(string) $row->getAttribute('data-advance-category')] = trim(new Crawler($row)->filter('.value')->text());
        }

        return $allocation;
    }

    /** @param list<string> $slugs */
    private function validateOrderFor(Player $player, array $slugs): Order
    {
        $order = OrderBuilder::for($player)->withKeys(...$slugs)->persist($this->entityManager);

        self::getContainer()->get(OrderValidator::class)->validate($order);

        return $order;
    }

    private function freshOrderRepository(): OrderRepository
    {
        return self::getContainer()->get(OrderRepository::class);
    }
}
