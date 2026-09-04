<?php

declare(strict_types=1);

namespace App\Tests\Component;

use App\Presentation\Shop\CartKey;
use App\State\Game;
use App\State\Order;
use App\State\Player;
use App\Engine\Shop\AdvanceFulfillment;
use App\Infrastructure\Repository\OrderRepository;
use App\Tests\Support\Fixture\Tables;
use App\Tests\Support\GameFixtureTrait;
use App\Tests\Support\ShopFixtureTrait;
use Userforged\ShopEngine\Cart;
use Userforged\ShopEngine\Dto\OrderLine;
use Userforged\ShopEngine\OrderStatus;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\UX\LiveComponent\Test\InteractsWithLiveComponents;
use Symfony\UX\LiveComponent\Test\TestLiveComponent;

final class KioskOperatorFlowTest extends WebTestCase
{
    use GameFixtureTrait;
    use InteractsWithLiveComponents;
    use ShopFixtureTrait;

    #[Test]
    public function validatingFreezesTheLinesGrantsTheAdvancesAndValidatesTheOrder(): void
    {
        [$game, $alice] = $this->aliceAndBobWithHerCreditsPosted();

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
    }

    #[Test]
    public function aliceKioskLocksForTheTurnAfterValidationWhileBobKioskStaysOpen(): void
    {
        [$game, $alice, $bob] = $this->aliceAndBobWithHerCreditsPosted();

        $this->submitAndValidateAliceOrder($alice, $game);

        $this->assertTrue($this->createLiveComponent('Shop', ['player' => $alice])->component()->isLockedForTurn());

        $bobShop = $this->createLiveComponent('Shop', ['player' => $bob]);

        $this->assertFalse($bobShop->component()->isLockedForTurn());
        $this->assertMatchesRegularExpression('/id="product-democracy".*?data-price-net>220</s', $bobShop->render()->toString());
    }

    #[Test]
    public function nextTurnUnlocksAliceKioskAndRepricesHerCatalogWithNewCredits(): void
    {
        [$game, $alice] = $this->aliceAndBobWithHerCreditsPosted();

        $this->submitAndValidateAliceOrder($alice, $game);

        $this->createLiveComponent('OperatorBoard', ['game' => $game])->call('nextTurn');
        $this->assertSame(2, $this->reloadGame($game)->currentTurn);

        $aliceShop = $this->createLiveComponent('Shop', ['player' => $alice]);
        $this->assertFalse($aliceShop->component()->isLockedForTurn());

        $rendered = $aliceShop->render()->toString();
        $this->assertMatchesRegularExpression('/id="product-law".*?data-price-net>130</s', $rendered);
    }

    #[Test]
    public function cartAdditionsInAliceKioskNeverAppearInBobKiosk(): void
    {
        [, $alice, $bob] = $this->aliceAndBobWithHerCreditsPosted();
        $client = self::getContainer()->get('test.client');

        $this->seedCart($client, CartKey::shop($alice), Cart::fromKeys(['pottery']));
        $bobCrawler = $this->createLiveComponent('Shop', ['player' => $bob], $client)->render()->crawler();

        $this->assertTrue($bobCrawler->filter('[data-live-action-param="checkout"]')->getNode(0)->hasAttribute('disabled'));
    }

    /**
     * The mother makes Alice own agriculture but posts no credits; this grants it again through
     * AdvanceFulfillment, so the prices below are the ones its credits buy.
     *
     * @return array{Game, Player, Player}
     */
    private function aliceAndBobWithHerCreditsPosted(): array
    {
        [$game, $alice, $bob] = Tables::aliceAndBob($this->entityManager);

        self::getContainer()->get(AdvanceFulfillment::class)->grant($alice->id, ['agriculture'], $alice->game->currentTurn);
        $this->entityManager->flush();

        return [$game, $alice, $bob];
    }

    private function submitAliceDemocracyAndPotteryOrder(Player $alice): void
    {
        $client = self::getContainer()->get('test.client');
        $this->seedCart($client, CartKey::shop($alice), Cart::fromKeys(['democracy', 'pottery']));

        $this->createCart($alice, $client)->call('checkout');
    }

    private function validateOrder(Order $order): void
    {
        $client = self::getContainer()->get('test.client');
        $this->createLiveComponent('CashierTerminal', [
            'game' => $order->player->game,
            'turn' => $order->turn,
        ], $client)->set('playerSlug', $order->player->slug);

        $this->createPosCart($order->player, $order->turn, $client)->call('checkout');
    }

    private function createCart(Player $player, KernelBrowser $client): TestLiveComponent
    {
        return $this->createLiveComponent('Cart', [
            'player' => $player,
            'storageKey' => CartKey::shop($player),
        ], $client);
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

    private function submitAndValidateAliceOrder(Player $alice, Game $game): void
    {
        $this->submitAliceDemocracyAndPotteryOrder($alice);
        $order = $this->freshOrderRepository()->findOneByPlayerAndWindow($alice, $game->currentTurn);
        $this->assertInstanceOf(Order::class, $order);

        $this->validateOrder($order);
    }

    private function freshEntityManager(): EntityManagerInterface
    {
        return self::getContainer()->get(EntityManagerInterface::class);
    }

    private function freshOrderRepository(): OrderRepository
    {
        return self::getContainer()->get(OrderRepository::class);
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
}
