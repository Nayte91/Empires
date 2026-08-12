<?php

declare(strict_types=1);

namespace App\Tests\Component;

use App\State\Game;
use App\State\Order;
use App\State\Player;
use App\Engine\Shop\AdvanceFulfillment;
use App\Infrastructure\Repository\OrderRepository;
use App\Tests\Support\Fixture\GameBuilder;
use App\Tests\Support\Fixture\PlayerBuilder;
use App\Tests\Support\GameFixtureTrait;
use Userforged\ShopEngine\Cart;
use Userforged\ShopEngine\CartStorageInterface;
use Userforged\ShopEngine\Dto\OrderLine;
use Userforged\ShopEngine\OrderStatus;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\BrowserKit\Cookie;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\UX\LiveComponent\Test\InteractsWithLiveComponents;
use Symfony\UX\LiveComponent\Test\TestLiveComponent;

final class KioskOperatorFlowTest extends WebTestCase
{
    use GameFixtureTrait;
    use InteractsWithLiveComponents;

    #[Test]
    public function validatingFreezesLinesGrantsAdvancesAndValidatesTheOrderAndTheCardShowsItsTotalAndVictoryPoints(): void
    {
        [$game, $alice] = $this->createGameWithAliceAndBob();

        $this->submitAliceDemocracyAndPotteryOrder($alice);
        $order = $this->freshOrderRepository()->findOneByPlayerAndWindow($alice, $game->currentTurn);
        $this->assertInstanceOf(Order::class, $order);

        $this->validateOrder($order);

        $reloadedOrder = $this->reloadOrder($order);

        $this->assertSame(OrderStatus::Validated, $reloadedOrder->status);
        $this->assertEquals([
            new OrderLine('democracy', 200),
            new OrderLine('pottery', 50),
        ], $reloadedOrder->lines);
        $this->assertSame(250, $reloadedOrder->total);
        $this->assertSame(['agriculture', 'democracy', 'pottery'], $this->reloadPlayer($alice)->advances);

        $rendered = $this->createLiveComponent('PlayerOrders', [
            'player' => $alice,
            'ordersStamp' => '',
        ])->render()->toString();

        $this->assertStringContainsString('Total: 250', $rendered);
        $this->assertStringContainsString('VP: 7', $rendered);
        $this->assertStringContainsString('data-status="validated"', $rendered);
    }

    #[Test]
    public function aliceKioskLocksForTheTurnAfterValidationWhileBobKioskStaysOpen(): void
    {
        [$game, $alice, $bob] = $this->createGameWithAliceAndBob();

        $this->submitAndValidateAliceOrder($alice, $game);

        $aliceShop = $this->createLiveComponent('Shop', ['player' => $alice]);
        $this->assertTrue($this->getShopComponent($aliceShop)->isLockedForTurn());

        $aliceRendered = $aliceShop->render()->toString();
        $this->assertStringContainsString('data-status="validated"', $aliceRendered);
        $this->assertStringNotContainsString('id="product-democracy"', $aliceRendered);
        $this->assertStringContainsString('Democracy', $aliceRendered);

        $bobShop = $this->createLiveComponent('Shop', ['player' => $bob]);
        $this->assertFalse($this->getShopComponent($bobShop)->isLockedForTurn());

        $bobRendered = $bobShop->render()->toString();
        $this->assertMatchesRegularExpression('/id="product-democracy".*?data-price-net>220</s', $bobRendered);
    }

    #[Test]
    public function nextTurnUnlocksAliceKioskAndRepricesHerCatalogWithNewCredits(): void
    {
        [$game, $alice] = $this->createGameWithAliceAndBob();

        $this->submitAndValidateAliceOrder($alice, $game);

        $this->createLiveComponent('OperatorConsole', ['game' => $game])->call('nextTurn');
        $this->assertSame(2, $this->reloadGame($game)->currentTurn);

        $aliceShop = $this->createLiveComponent('Shop', ['player' => $alice]);
        $this->assertFalse($this->getShopComponent($aliceShop)->isLockedForTurn());

        $rendered = $aliceShop->render()->toString();
        $this->assertMatchesRegularExpression('/id="product-law".*?data-price-net>130</s', $rendered);
    }

    #[Test]
    public function cartAdditionsInAliceKioskNeverAppearInBobKiosk(): void
    {
        [, $alice, $bob] = $this->createGameWithAliceAndBob();
        $client = self::getContainer()->get('test.client');

        $this->cartFor($client, $alice, Cart::fromKeys(['pottery']));
        $bobCrawler = $this->createLiveComponent('Shop', ['player' => $bob], $client)->render()->crawler();

        $this->assertTrue($bobCrawler->filter('[data-live-action-param="checkout"]')->getNode(0)->hasAttribute('disabled'));
    }

    /** @return array{Game, Player, Player} */
    private function createGameWithAliceAndBob(): array
    {
        $game = GameBuilder::create()->build();
        $alice = PlayerBuilder::named('Alice')->in($game)->persist($this->entityManager);
        $bob = PlayerBuilder::named('Bob')->in($game)->persist($this->entityManager);

        self::getContainer()->get(AdvanceFulfillment::class)->grant($alice->id, ['agriculture']);
        $this->entityManager->flush();

        return [$game, $alice, $bob];
    }

    private function submitAliceDemocracyAndPotteryOrder(Player $alice): void
    {
        $client = self::getContainer()->get('test.client');
        $this->cartFor($client, $alice, Cart::fromKeys(['democracy', 'pottery']));

        $this->createCart($alice, $client)->call('checkout');
    }

    private function validateOrder(Order $order): void
    {
        $client = self::getContainer()->get('test.client');
        $component = $this->createLiveComponent('PlayerOrders', [
            'player' => $order->player,
            'ordersStamp' => '',
        ], $client);
        $component->call('openPos', ['turn' => $order->turn]);

        $this->createPosCart($order->player, $order->turn, $client)->call('checkout');
    }

    private function createCart(Player $player, KernelBrowser $client): TestLiveComponent
    {
        return $this->createLiveComponent('Cart', [
            'player' => $player,
            'storageKey' => (string) $player->id,
        ], $client);
    }

    private function createPosCart(Player $player, int $turn, KernelBrowser $client): TestLiveComponent
    {
        return $this->createLiveComponent('Cart', [
            'player' => $player,
            'storageKey' => 'pos.'.$player->id->toRfc4122(),
            'directSale' => true,
            'window' => $turn,
        ], $client);
    }

    private function submitAndValidateAliceOrder(Player $alice, Game $game): void
    {
        $this->submitAliceDemocracyAndPotteryOrder($alice);
        $order = $this->freshOrderRepository()->findOneByPlayerAndWindow($alice, $game->currentTurn);
        $this->assertInstanceOf(Order::class, $order);

        $this->validateOrder($order);
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

    private function freshEntityManager(): EntityManagerInterface
    {
        return self::getContainer()->get(EntityManagerInterface::class);
    }

    private function freshOrderRepository(): OrderRepository
    {
        return self::getContainer()->get(OrderRepository::class);
    }

    private function reloadPlayer(Player $player): Player
    {
        $reloaded = $this->freshEntityManager()->find(Player::class, $player->id);
        $this->assertInstanceOf(Player::class, $reloaded);

        return $reloaded;
    }

    private function reloadGame(Game $game): Game
    {
        $reloaded = $this->freshEntityManager()->find(Game::class, $game->id);
        $this->assertInstanceOf(Game::class, $reloaded);

        return $reloaded;
    }

    private function reloadOrder(Order $order): Order
    {
        $reloaded = $this->freshEntityManager()->find(Order::class, $order->id);
        $this->assertInstanceOf(Order::class, $reloaded);

        return $reloaded;
    }

    private function getShopComponent(object $component): object
    {
        return $component->component();
    }
}
