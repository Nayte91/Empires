<?php

declare(strict_types=1);

namespace App\Tests\Component;

use App\Infrastructure\Repository\OrderRepository;
use App\State\Order;
use App\State\Player;
use App\Tests\Support\Fixture\OrderBuilder;
use App\Tests\Support\Fixture\Tables;
use App\Tests\Support\GameFixtureTrait;
use App\Tests\Support\ShopFixtureTrait;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\UX\LiveComponent\Test\InteractsWithLiveComponents;
use Symfony\UX\LiveComponent\Test\TestLiveComponent;
use Userforged\ShopEngine\Service\OrderValidator;

final class PlayerOrdersTest extends WebTestCase
{
    use GameFixtureTrait;
    use InteractsWithLiveComponents;
    use ShopFixtureTrait;

    #[Test]
    public function eraseOrderCascadesRemovingLaterTurnsAndDisowningAdvances(): void
    {
        [$game, $alice] = Tables::aliceAndBob($this->entityManager);
        $game->currentTurn = 1;
        $this->entityManager->flush();
        $this->validateOrderFor($alice, ['democracy']);

        $game->currentTurn = 2;
        $this->entityManager->flush();
        $this->validateOrderFor($alice, ['pottery']);

        $this->createPlayerOrders($alice)->call('eraseOrder', ['turn' => 1]);

        $this->assertNotInstanceOf(Order::class, $this->freshOrderRepository()->findOneByPlayerAndWindow($alice, 1));
        $this->assertNotInstanceOf(Order::class, $this->freshOrderRepository()->findOneByPlayerAndWindow($alice, 2));

        $reloadedAlice = $this->reloadPlayer($alice);
        $this->assertNotContains('democracy', $reloadedAlice->advances);
        $this->assertNotContains('pottery', $reloadedAlice->advances);
        $this->assertContains('agriculture', $reloadedAlice->advances);
    }

    #[Test]
    public function cardsRunFromTheCurrentTurnDownToTheFirstAndOnlyPastTurnsWithNoOrderReadEmpty(): void
    {
        [$game, , $bob] = Tables::aliceAndBob($this->entityManager);
        $game->currentTurn = 3;
        $this->entityManager->flush();
        OrderBuilder::for($bob)->withKeys('pottery')->persist($this->entityManager);

        $cards = $this->cardsOf($bob);

        $this->assertSame([3, 2, 1], array_column($cards, 'turn'));
        $this->assertSame(['pending', 'empty', 'empty'], array_column($cards, 'status'));
    }

    #[Test]
    public function theCurrentTurnWithNothingSubmittedIsMissingAndWorthNothing(): void
    {
        [, , $bob] = Tables::aliceAndBob($this->entityManager);

        $card = $this->cardsOf($bob)[0];

        $this->assertSame('missing', $card['status']);
        $this->assertSame(0, $card['total']);
        $this->assertSame(0, $card['vp']);
    }

    #[Test]
    public function aPendingCardCarriesTheRecomputedNetCostOfItsLines(): void
    {
        [, , $bob] = Tables::aliceAndBob($this->entityManager);
        OrderBuilder::for($bob)->withKeys('pottery')->persist($this->entityManager);

        $card = $this->cardsOf($bob)[0];

        $this->assertSame('pending', $card['status']);
        $this->assertSame(['pottery'], $card['slugs']);
        $this->assertSame(60, $card['total']);
        $this->assertSame(1, $card['vp']);
    }

    #[Test]
    public function aValidatedCardCarriesItsFrozenTotalAndSwapsTheTillLinkForTheEraseModal(): void
    {
        [, , $bob] = Tables::aliceAndBob($this->entityManager);
        $this->validateOrderFor($bob, ['democracy', 'pottery']);

        $card = $this->cardsOf($bob)[0];
        $rendered = $this->createPlayerOrders($bob)->render()->crawler();

        $this->assertSame('validated', $card['status']);
        $this->assertSame(280, $card['total']);
        $this->assertSame(7, $card['vp']);
        $this->assertCount(1, $rendered->filter('article button[commandfor]'));
        $this->assertCount(0, $rendered->filter('article a[href$="/operator/pos"]'));
    }

    /** @return list<array{turn: int, status: string, slugs: list<string>, total: int, vp: int}> */
    private function cardsOf(Player $player): array
    {
        return $this->createPlayerOrders($player)->component()->getCards();
    }

    private function createPlayerOrders(Player $player): TestLiveComponent
    {
        return $this->createLiveComponent('PlayerOrders', ['player' => $player, 'ordersStamp' => '']);
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
