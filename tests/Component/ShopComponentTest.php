<?php

declare(strict_types=1);

namespace App\Tests\Component;

use App\Infrastructure\Repository\OrderRepository;
use App\Presentation\Shop\CartKey;
use App\Presentation\Shop\CatalogSort;
use App\Presentation\Shop\CatalogView;
use App\State\Order;
use App\State\Player;
use App\Tests\Support\Fixture\OrderBuilder;
use App\Tests\Support\Fixture\PlayerBuilder;
use App\Tests\Support\GameFixtureTrait;
use App\Tests\Support\ShopFixtureTrait;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\UX\LiveComponent\Test\InteractsWithLiveComponents;
use Symfony\UX\LiveComponent\Test\TestLiveComponent;
use Userforged\ShopEngine\Cart;
use Userforged\ShopEngine\CartStorageInterface;
use Userforged\ShopEngine\Dto\LineIntent;
use Userforged\ShopEngine\Dto\OrderLine;
use Userforged\ShopEngine\OrderStatus;

final class ShopComponentTest extends WebTestCase
{
    use GameFixtureTrait;
    use InteractsWithLiveComponents;
    use ShopFixtureTrait;

    #[Test]
    public function addPutsTheProductInTheCart(): void
    {
        $player = PlayerBuilder::named('Alice')->persist($this->entityManager);
        $client = $this->browser();

        $this->createLiveComponent('Shop', ['player' => $player], $client)->call('add', ['key' => 'pottery']);

        $this->assertSame(['pottery'], $this->cartKeysOf($client, $player));
    }

    #[Test]
    public function addIsANoOpAndSetsAnErrorWhenTheCartIsLocked(): void
    {
        $player = PlayerBuilder::named('Alice')->persist($this->entityManager);
        OrderBuilder::for($player)->withLine(new OrderLine('pottery', 60))->validated(60)->persist($this->entityManager);
        $client = $this->browser();

        $component = $this->createLiveComponent('Shop', ['player' => $player], $client);
        $crawler = $component->call('add', ['key' => 'democracy'])->render()->crawler();

        $this->assertCount(1, $crawler->filter('[role="alert"]'));
        $this->assertTrue($component->component()->isLockedForTurn());
        $this->assertSame([], $this->cartKeysOf($client, $player));
    }

    #[Test]
    public function addingAnAlreadyOwnedAdvanceIsRefused(): void
    {
        $player = PlayerBuilder::named('Alice')->persist($this->entityManager);
        $player->ownAdvances(['agriculture']);
        $this->entityManager->flush();
        $client = $this->browser();

        $crawler = $this->createLiveComponent('Shop', ['player' => $player], $client)
            ->call('add', ['key' => 'agriculture'])
            ->render()
            ->crawler()
        ;

        $this->assertCount(1, $crawler->filter('[role="alert"]'));
        $this->assertSame([], $this->cartKeysOf($client, $player));
    }

    #[Test]
    public function addingTheSameAdvanceTwiceIsDeduped(): void
    {
        $player = PlayerBuilder::named('Alice')->persist($this->entityManager);
        $client = $this->browser();

        $component = $this->createLiveComponent('Shop', ['player' => $player], $client);
        $component->call('add', ['key' => 'pottery']);
        $component->call('add', ['key' => 'pottery']);

        $this->assertSame(['pottery'], $this->cartKeysOf($client, $player));
    }

    #[Test]
    public function submitOrderCreatesAPendingOrderAndEmptiesTheCart(): void
    {
        $player = PlayerBuilder::named('Alice')->persist($this->entityManager);
        $client = $this->browser();
        $this->seedCart($client, CartKey::shop($player), Cart::fromKeys(['pottery']));

        $this->createCart($player, $client)->call('checkout');

        $order = $this->freshOrderRepository()->findOneByPlayerAndWindow($player, $player->game->currentTurn);
        $this->assertInstanceOf(Order::class, $order);
        $this->assertSame(['pottery'], $order->keys());
        $this->assertSame([], $this->cartKeysOf($client, $player));

        $component = $this->createLiveComponent('Shop', ['player' => $player], $client);
        $this->assertSame('pending', $component->component()->getOrderStatusHook());
    }

    #[Test]
    public function aPendingOrderTurnsTheCartIntoAReadOnlyTicketThatStaysEditable(): void
    {
        $player = PlayerBuilder::named('Alice')->persist($this->entityManager);
        OrderBuilder::for($player)->withKeys('pottery')->persist($this->entityManager);

        $component = $this->createLiveComponent('Shop', ['player' => $player])->component();

        $this->assertSame('pending', $component->getOrderStatusHook());
        $this->assertInstanceOf(Order::class, $component->getPendingOrder());
        $this->assertFalse($component->isLockedForTurn());
    }

    #[Test]
    public function editPendingOrderReloadsItsLinesIntoTheCartAndWithdrawsTheOrder(): void
    {
        $player = PlayerBuilder::named('Alice')->persist($this->entityManager);
        OrderBuilder::for($player)->withKeys('pottery', 'agriculture')->persist($this->entityManager);
        $client = $this->browser();

        $component = $this->createLiveComponent('Shop', ['player' => $player], $client);
        $component->call('editPendingOrder');

        $this->assertSame(['pottery', 'agriculture'], $this->cartKeysOf($client, $player));
        $this->assertNull($component->component()->getPendingOrder());
    }

    #[Test]
    #[DataProvider('provideTheCartTellsThePlayerWhereTheirOrderStandsCases')]
    public function theCartTellsThePlayerWhereTheirOrderStands(bool $withOrder, string $status, string $wording): void
    {
        $player = PlayerBuilder::named('Alice')->persist($this->entityManager);

        if ($withOrder) {
            OrderBuilder::for($player)->withKeys('pottery')->persist($this->entityManager);
        }

        $component = $this->createLiveComponent('Shop', ['player' => $player])->component();

        $this->assertSame($status, $component->getOrderStatusHook());
        $this->assertStringContainsString($wording, (string) $component->getOrderHint());
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

        $client = $this->browser();
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
    public function aValidatedOrderLocksTheKioskForTheTurnAtItsFrozenTotal(): void
    {
        $player = PlayerBuilder::named('Alice')->persist($this->entityManager);
        OrderBuilder::for($player)->withLine(new OrderLine('pottery', 999))->validated(999)->persist($this->entityManager);

        $component = $this->createLiveComponent('Shop', ['player' => $player])->component();

        $this->assertTrue($component->isLockedForTurn());
        $this->assertSame('validated', $component->getOrderStatusHook());
        $this->assertNull($component->getPendingOrder());
        $this->assertSame(999, $component->getOrderTotal());
    }

    #[Test]
    public function aValidatedOrderKeepsTheKioskLockedEvenWithLeftoverCartItems(): void
    {
        $player = PlayerBuilder::named('Alice')->persist($this->entityManager);
        OrderBuilder::for($player)->withLine(new OrderLine('pottery', 60))->validated(60)->persist($this->entityManager);

        $client = $this->browser();
        $this->seedCart($client, CartKey::shop($player), Cart::fromKeys(['democracy']));

        $component = $this->createLiveComponent('Shop', ['player' => $player], $client)->component();

        $this->assertSame(['democracy'], $this->cartKeysOf($client, $player));
        $this->assertTrue($component->isLockedForTurn());
    }

    #[Test]
    public function anEmptiedBudgetFieldIsNoConstraintWhereABudgetOfZeroIsOne(): void
    {
        $player = PlayerBuilder::named('Alice')->persist($this->entityManager);
        $emptiedClient = $this->browser();
        $zeroedClient = $this->browser();
        $mountedClient = $this->browser();

        $emptied = $this->createLiveComponent('Shop', ['player' => $player], $emptiedClient)->set('budget', '');
        $zeroed = $this->createLiveComponent('Shop', ['player' => $player], $zeroedClient)->set('budget', 0);
        $mounted = $this->createLiveComponent('Shop', ['player' => $player, 'budget' => 110], $mountedClient);

        $this->assertNull($emptied->component()->budget);
        $this->assertNull($this->remainingBudgetOf($emptiedClient, $emptied));
        $this->assertSame(0, $zeroed->component()->budget);
        $this->assertSame(0, $this->remainingBudgetOf($zeroedClient, $zeroed));
        $this->assertSame(110, $this->remainingBudgetOf($mountedClient, $mounted));
    }

    #[Test]
    public function addingToTheCartDrawsTheRemainderDown(): void
    {
        $player = PlayerBuilder::named('Alice')->persist($this->entityManager);
        $client = $this->browser();

        $component = $this->createLiveComponent('Shop', ['player' => $player, 'budget' => 110], $client);

        $this->assertSame(110, $this->remainingBudgetOf($client, $component));

        $component->call('add', ['key' => 'pottery']);

        $this->assertSame(50, $this->remainingBudgetOf($client, $component));
    }

    #[Test]
    public function removingFromTheCartRestoresTheRemainder(): void
    {
        $player = PlayerBuilder::named('Alice')->persist($this->entityManager);
        $client = $this->browser();
        $this->seedCart($client, CartKey::shop($player), Cart::fromKeys(['pottery']));

        $withPottery = $this->createLiveComponent('Shop', ['player' => $player, 'budget' => 110], $client);
        $this->assertSame(50, $this->remainingBudgetOf($client, $withPottery));

        $this->createCart($player, $client)->call('remove', ['key' => 'pottery']);

        $withoutPottery = $this->createLiveComponent('Shop', ['player' => $player, 'budget' => 110], $client);
        $this->assertSame(110, $this->remainingBudgetOf($client, $withoutPottery));
    }

    #[Test]
    public function aBudgetSmallerThanTheCartLeavesTheRemainderNegative(): void
    {
        $player = PlayerBuilder::named('Alice')->persist($this->entityManager);
        $client = $this->browser();
        $this->seedCart($client, CartKey::shop($player), Cart::fromKeys(['pottery']));

        $component = $this->createLiveComponent('Shop', ['player' => $player, 'budget' => 5], $client);

        $this->assertSame(-55, $this->remainingBudgetOf($client, $component));
    }

    #[Test]
    public function aBudgetFarSmallerThanTheOrderStillLetsThePlayerSubmitIt(): void
    {
        $player = PlayerBuilder::named('Alice')->persist($this->entityManager);
        $client = $this->browser();
        $this->seedCart($client, CartKey::shop($player), Cart::fromKeys(['pottery', 'democracy']));

        $this->createCart($player, $client)->call('checkout');

        $order = $this->freshOrderRepository()->findOneByPlayerAndWindow($player, $player->game->currentTurn);

        $this->assertInstanceOf(Order::class, $order);
        $this->assertSame(['pottery', 'democracy'], $order->keys());
        $this->assertSame(OrderStatus::Pending, $order->status);
    }

    #[Test]
    public function theBudgetAndTheSortAreTheKiosksAloneAndNeverReachTheOperatorsPos(): void
    {
        $player = PlayerBuilder::named('Alice')->persist($this->entityManager);
        $kioskClient = $this->browser();
        $posClient = $this->browser();

        $kiosk = $this->catalogViewOf($kioskClient, $this->createLiveComponent('Shop', ['player' => $player, 'budget' => 5], $kioskClient));
        $pos = $this->catalogViewOf($posClient, $this->openPos($player, $posClient));

        $this->assertSame(5, $kiosk->remainingBudget);
        $this->assertSame(CatalogSort::NetPrice, $kiosk->sort);
        $this->assertNull($pos->remainingBudget);
        $this->assertSame(CatalogSort::ListPrice, $pos->sort);
    }

    #[Test]
    public function choosingASortCarriesItIntoTheCatalogView(): void
    {
        $player = PlayerBuilder::named('Alice')->persist($this->entityManager);
        $client = $this->browser();

        $component = $this->createLiveComponent('Shop', ['player' => $player], $client);
        $this->assertSame(CatalogSort::NetPrice, $this->catalogViewOf($client, $component)->sort);

        $component->set('sort', 'name');

        $this->assertSame(CatalogSort::Name, $this->catalogViewOf($client, $component)->sort);
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
    public function editPendingOrderReloadsTheGiftChoiceIntoTheCart(): void
    {
        $player = PlayerBuilder::named('Alice')->persist($this->entityManager);
        $client = $this->browser();
        $cart = Cart::fromKeys(['anatomy']);
        $cart->withGift('anatomy', 'astronavigation');
        $this->seedCart($client, CartKey::shop($player), $cart);

        $this->createCart($player, $client)->call('checkout');

        $this->createLiveComponent('Shop', ['player' => $player], $client)->call('editPendingOrder');

        $this->assertSame('astronavigation', $this->cartIntentOf($client, $player, 'anatomy')->gift);
    }

    #[Test]
    public function editPendingOrderReloadsTheAllocationIntoTheCart(): void
    {
        $player = PlayerBuilder::named('Alice')->persist($this->entityManager);
        $client = $this->browser();
        $cart = Cart::fromKeys(['monument']);
        $cart->withAllocation('monument', 'craft', 10);
        $cart->withAllocation('monument', 'science', 10);
        $this->seedCart($client, CartKey::shop($player), $cart);

        $this->createCart($player, $client)->call('checkout');

        $this->createLiveComponent('Shop', ['player' => $player], $client)->call('editPendingOrder');

        $this->assertSame(['craft' => 10, 'science' => 10], $this->cartIntentOf($client, $player, 'monument')->allocation);
    }

    private function createCart(Player $player, KernelBrowser $client): TestLiveComponent
    {
        return $this->createLiveComponent('Cart', [
            'player' => $player,
            'storageKey' => CartKey::shop($player),
        ], $client);
    }

    /** The till reads its cart at mount, so unlike the kiosk it cannot be built outside a request with a session. */
    private function openPos(Player $player, KernelBrowser $client): TestLiveComponent
    {
        return $this->createLiveComponent('CashierTerminal', [
            'game' => $player->game,
            'turn' => $player->game->currentTurn,
            'playerSlug' => $player->slug,
        ], $client);
    }

    /** @return list<string> */
    private function cartKeysOf(KernelBrowser $client, Player $player): array
    {
        return $this->reopening($client, fn (): array => self::getContainer()
            ->get(CartStorageInterface::class)
            ->load(CartKey::shop($player))
            ->keys());
    }

    private function cartIntentOf(KernelBrowser $client, Player $player, string $key): LineIntent
    {
        $items = $this->reopening($client, fn (): array => self::getContainer()
            ->get(CartStorageInterface::class)
            ->load(CartKey::shop($player))
            ->items);
        $intent = array_find($items, static fn (LineIntent $item): bool => $item->key === $key);

        $this->assertInstanceOf(LineIntent::class, $intent);

        return $intent;
    }

    private function remainingBudgetOf(KernelBrowser $client, TestLiveComponent $component): ?int
    {
        $mounted = $component->component();

        return $this->reopening($client, static fn (): ?int => $mounted->getRemainingBudget());
    }

    private function catalogViewOf(KernelBrowser $client, TestLiveComponent $component): CatalogView
    {
        $mounted = $component->component();

        return $this->reopening($client, static fn (): CatalogView => $mounted->getCatalogView());
    }

    private function freshOrderRepository(): OrderRepository
    {
        return self::getContainer()->get(OrderRepository::class);
    }
}
