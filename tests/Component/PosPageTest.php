<?php

declare(strict_types=1);

namespace App\Tests\Component;

use App\Infrastructure\Repository\OrderRepository;
use App\Presentation\Shop\CartKey;
use App\State\Game;
use App\State\Order;
use App\State\Player;
use App\Tests\Support\Fixture\GameBuilder;
use App\Tests\Support\Fixture\PlayerBuilder;
use App\Tests\Support\GameFixtureTrait;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\BrowserKit\Cookie;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\UX\LiveComponent\Test\InteractsWithLiveComponents;
use Symfony\UX\LiveComponent\Test\TestLiveComponent;
use Userforged\ShopEngine\Cart;
use Userforged\ShopEngine\CartStorageInterface;
use Userforged\ShopEngine\Dto\OrderLine;
use Userforged\ShopEngine\OrderStatus;
use Userforged\ShopEngine\Promotion\AppliedPromotion;
use Userforged\ShopEngine\Promotion\PromotionType;
use Userforged\ShopEngine\Service\OrderValidator;

final class PosPageTest extends WebTestCase
{
    use GameFixtureTrait;
    use InteractsWithLiveComponents;

    #[Test]
    public function withNoBuyerChosenTheTillShowsOnlyItsBuyerSelect(): void
    {
        [$game] = $this->createGameWithAliceAndBob();

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
        [$game] = $this->createGameWithAliceAndBob();

        $component = $this->createPos($game)->set('playerSlug', $slug);
        $crawler = $component->render()->crawler();

        $this->assertNull($component->component()->getPlayer());
        $this->assertCount(0, $crawler->filter('[data-live-action-param="checkout"]'));
        $this->assertCount(0, $crawler->filter('button[id^="product-"]'));
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
        [, , $bob] = $this->createGameWithAliceAndBob();
        $this->createPendingOrderFor($bob, ['pottery']);

        $crawler = $this->createPos($bob->game)->set('playerSlug', $bob->slug)->render()->crawler();

        $this->assertFalse($crawler->filter('[data-live-action-param="checkout"]')->getNode(0)->hasAttribute('disabled'));
        $this->assertCount(0, $crawler->filter('#product-pottery'));
    }

    #[Test]
    public function choosingABuyerWhoseTillAlreadyHoldsItemsKeepsWhatTheOperatorBuilt(): void
    {
        [$game, , $bob] = $this->createGameWithAliceAndBob();
        $client = self::getContainer()->get('test.client');
        $this->createPendingOrderFor($bob, ['pottery']);
        $this->posCartFor($client, $bob, $game->currentTurn, Cart::fromKeys(['democracy']));

        $crawler = $this->createPos($game, client: $client)->set('playerSlug', $bob->slug)->render()->crawler();

        $this->assertCount(0, $crawler->filter('#product-democracy'));
        $this->assertCount(1, $crawler->filter('#product-pottery'));
    }

    #[Test]
    public function choosingABuyerWithNothingSubmittedForTheTurnOpensAnEmptyTill(): void
    {
        [, , $bob] = $this->createGameWithAliceAndBob();

        $crawler = $this->createPos($bob->game)->set('playerSlug', $bob->slug)->render()->crawler();

        $this->assertTrue($crawler->filter('[data-live-action-param="checkout"]')->getNode(0)->hasAttribute('disabled'));
    }

    #[Test]
    public function aValidatedTurnShowsItsReceiptAndNoWayToBuyMore(): void
    {
        [, , $bob] = $this->createGameWithAliceAndBob();
        $order = $this->validateOrderFor($bob, ['pottery']);

        $crawler = $this->createPos($bob->game)->set('playerSlug', $bob->slug)->render()->crawler();

        $this->assertStringContainsString('validated', $crawler->filter('.hint')->text());
        $this->assertStringContainsString('Pottery', $crawler->filter('.lines')->text());
        $this->assertSame('Total: '.$order->total, trim($crawler->filter('.total')->text()));

        $this->assertCount(0, $crawler->filter('[data-live-action-param="checkout"]'));
        $this->assertCount(0, $crawler->filter('button[id^="product-"]'));
    }

    #[Test]
    public function aValidatedTurnOffersTheConsolesCascadingEraseBehindItsConfirmation(): void
    {
        [$game, , $bob] = $this->createGameWithAliceAndBob();
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
        [, , $bob] = $this->createGameWithAliceAndBob();
        $this->validateOrderFor($bob, ['pottery']);

        $this->createPos($bob->game)->set('playerSlug', $bob->slug)->call('eraseOrder');

        $this->assertNotInstanceOf(
            Order::class,
            self::getContainer()->get(OrderRepository::class)->findOneByPlayerAndWindow($bob, $bob->game->currentTurn),
        );

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->clear();
        $reloaded = $entityManager->find(Player::class, $bob->id);
        $this->assertInstanceOf(Player::class, $reloaded);
        $this->assertNotContains('pottery', $reloaded->advances);
    }

    #[Test]
    public function addTakesTheProductOutOfTheCatalogueAndUpdatesTheTicketTotal(): void
    {
        [, , $bob] = $this->createGameWithAliceAndBob();

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
        [, $alice] = $this->createGameWithAliceAndBob();

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
        [$game, , $bob] = $this->createGameWithAliceAndBob();
        $client = self::getContainer()->get('test.client');

        $this->posCartFor($client, $bob, $game->currentTurn, Cart::fromKeys(['pottery', 'democracy']));

        $this->createPosCart($bob, $game->currentTurn, $client)->call('checkout');

        $order = $this->freshOrderRepository()->findOneByPlayerAndWindow($bob, $game->currentTurn);
        $this->assertInstanceOf(Order::class, $order);
        $this->assertSame(OrderStatus::Validated, $order->status);
        $this->assertSame(['pottery', 'democracy'], $this->reloadPlayer($bob)->advances);
    }

    #[Test]
    public function checkoutWithAChosenGiftValidatesTheOrderWithTheZeroCostGiftLine(): void
    {
        [$game, , $bob] = $this->createGameWithAliceAndBob();
        $client = self::getContainer()->get('test.client');

        $ticket = Cart::fromKeys(['anatomy']);
        $ticket->withGift('anatomy', 'astronavigation');
        $this->posCartFor($client, $bob, $game->currentTurn, $ticket);

        $this->createPosCart($bob, $game->currentTurn, $client)->call('checkout');

        $order = $this->freshOrderRepository()->findOneByPlayerAndWindow($bob, $game->currentTurn);
        $this->assertInstanceOf(Order::class, $order);
        $this->assertSame(OrderStatus::Validated, $order->status);
        $this->assertSame(['anatomy', 'astronavigation'], $order->keys());

        $giftLine = $order->lines()[1];
        $this->assertSame('astronavigation', $giftLine->key);
        $this->assertSame(0, $giftLine->netCost);

        $this->assertSame(['anatomy', 'astronavigation'], $this->reloadPlayer($bob)->advances);
    }

    #[Test]
    public function checkoutWithAPartialAllocationIsRejectedServerSideAndShowsAnError(): void
    {
        [$game, , $bob] = $this->createGameWithAliceAndBob();
        $client = self::getContainer()->get('test.client');

        $ticket = Cart::fromKeys(['monument']);
        $ticket->withAllocation('monument', 'science', 5);
        $this->posCartFor($client, $bob, $game->currentTurn, $ticket);

        $rendered = $this->createPosCart($bob, $game->currentTurn, $client)->call('checkout')->render()->toString();

        $this->assertNotInstanceOf(Order::class, $this->freshOrderRepository()->findOneByPlayerAndWindow($bob, $game->currentTurn));
        $this->assertStringContainsString('Finish allocating the bonus for', $rendered);
        $this->assertStringContainsString('monument', $rendered);
    }

    #[Test]
    public function allocatingTheFullOptionPoolAtTheTillEnablesCheckoutAndPersistsTheAllocation(): void
    {
        [$game, , $bob] = $this->createGameWithAliceAndBob();
        $client = self::getContainer()->get('test.client');

        $ticket = Cart::fromKeys(['monument']);
        $ticket->withAllocation('monument', 'craft', 10);
        $ticket->withAllocation('monument', 'science', 10);
        $this->posCartFor($client, $bob, $game->currentTurn, $ticket);

        $crawler = $this->createPos($game, client: $client)->set('playerSlug', $bob->slug)->render()->crawler();
        $this->assertFalse($crawler->filter('[data-live-action-param="checkout"]')->getNode(0)->hasAttribute('disabled'));

        $this->createPosCart($bob, $game->currentTurn, $client)->call('checkout');

        $order = $this->freshOrderRepository()->findOneByPlayerAndWindow($bob, $game->currentTurn);
        $this->assertInstanceOf(Order::class, $order);
        $this->assertSame(OrderStatus::Validated, $order->status);

        $line = $order->lines()[0];
        $this->assertInstanceOf(AppliedPromotion::class, $line->promotion);
        $this->assertSame(PromotionType::Option, $line->promotion->type);
        $this->assertSame(['craft' => 10, 'science' => 10], $line->promotion->allocation);
    }

    #[Test]
    public function choosingABuyerWithAKioskSubmittedMonumentOrderReloadsTheAllocationIntoTheTill(): void
    {
        [$game, , $bob] = $this->createGameWithAliceAndBob();

        $order = new Order($bob, $game->currentTurn);
        $order->replaceLines([
            new OrderLine('monument', 180, new AppliedPromotion(PromotionType::Option, 'monument', allocation: ['craft' => 10, 'science' => 10])),
        ]);
        $this->entityManager->persist($order);
        $this->entityManager->flush();

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
        [$game, , $bob] = $this->createGameWithAliceAndBob();
        $game->currentTurn = 3;
        $this->entityManager->flush();
        $client = self::getContainer()->get('test.client');

        $this->posCartFor($client, $bob, 1, Cart::fromKeys(['pottery']));
        $this->createPosCart($bob, 1, $client)->call('checkout');

        $pastOrder = $this->freshOrderRepository()->findOneByPlayerAndWindow($bob, 1);
        $this->assertInstanceOf(Order::class, $pastOrder);
        $this->assertSame(OrderStatus::Validated, $pastOrder->status);

        $this->assertNotInstanceOf(Order::class, $this->freshOrderRepository()->findOneByPlayerAndWindow($bob, 3));
    }

    #[Test]
    public function theTillPreloadsTheOrderOfTheTurnItIsPointedAtNotTheCurrentOne(): void
    {
        [$game, , $bob] = $this->createGameWithAliceAndBob();
        $this->createPendingOrderFor($bob, ['pottery']);
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
        [$game, $alice] = $this->createGameWithAliceAndBob();
        $this->validateOrderFor($alice, ['democracy']);
        $client = self::getContainer()->get('test.client');

        $this->posCartFor($client, $alice, $game->currentTurn, Cart::fromKeys(['pottery']));
        $crawler = $this->createPosCart($alice, $game->currentTurn, $client)->call('checkout')->render()->crawler();

        $alert = $crawler->filter('p[role="alert"]');
        $this->assertCount(1, $alert);
        $this->assertNotSame('', trim($alert->text()));
    }

    /** @return array{Game, Player, Player} */
    private function createGameWithAliceAndBob(): array
    {
        $game = GameBuilder::create()->build();

        return [
            $game,
            PlayerBuilder::named('Alice')->in($game)->withAdvances(['agriculture'])->persist($this->entityManager),
            PlayerBuilder::named('Bob')->in($game)->persist($this->entityManager),
        ];
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

    private function posCartFor(KernelBrowser $client, Player $player, int $turn, Cart $cart): void
    {
        $session = self::getContainer()->get('session.factory')->createSession();
        $request = new Request();
        $request->setSession($session);
        $requestStack = self::getContainer()->get(RequestStack::class);
        $requestStack->push($request);
        self::getContainer()->get(CartStorageInterface::class)->save(CartKey::pos($player, $turn), $cart);
        $requestStack->pop();
        $session->save();

        $client->getCookieJar()->set(new Cookie($session->getName(), $session->getId()));
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
    private function createPendingOrderFor(Player $player, array $slugs): Order
    {
        $order = new Order($player, $player->game->currentTurn);
        $order->replaceLines(array_map(static fn (string $slug): OrderLine => new OrderLine($slug, 0), $slugs));
        $this->entityManager->persist($order);
        $this->entityManager->flush();

        return $order;
    }

    /** @param list<string> $slugs */
    private function validateOrderFor(Player $player, array $slugs): Order
    {
        $order = $this->createPendingOrderFor($player, $slugs);

        self::getContainer()->get(OrderValidator::class)->validate($order);

        return $order;
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
}
