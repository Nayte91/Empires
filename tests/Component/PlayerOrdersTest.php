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
    public function cardsRunFromCurrentTurnDownToOneAndAKioskPendingOrderFillsItsTurnCard(): void
    {
        [$game, , $bob] = Tables::aliceAndBob($this->entityManager);
        $game->currentTurn = 3;
        $this->entityManager->flush();
        OrderBuilder::for($bob)->withKeys('pottery')->persist($this->entityManager);

        $cards = $this->createPlayerOrders($bob)->render()->crawler()->filter('article');

        $this->assertCount(3, $cards);
        $this->assertStringContainsString('Turn 3', $cards->eq(0)->text());
        $this->assertSame('pending', $cards->eq(0)->attr('data-status'));
        $this->assertStringContainsString('Turn 2', $cards->eq(1)->text());
        $this->assertStringContainsString('Turn 1', $cards->eq(2)->text());
    }

    #[Test]
    public function aMissingCurrentTurnRendersAMissingStatusCardLinkingToTheTill(): void
    {
        [$game, , $bob] = Tables::aliceAndBob($this->entityManager);

        $crawler = $this->createPlayerOrders($bob)->render()->crawler();
        $card = $crawler->filter('article')->first();

        $this->assertSame('missing', $card->attr('data-status'));
        $this->assertStringContainsString('Empty', $card->text());
        $this->assertStringContainsString('Total: 0', $card->text());
        $this->assertStringContainsString('VP: 0', $card->text());
        $this->assertSame('Buy', trim($card->filter('a')->first()->text()));

        $this->assertSame(
            '/'.$game->slug.'/operator/pos?player='.$bob->slug.'&turn='.$game->currentTurn,
            $card->filter('a')->first()->attr('href'),
        );
    }

    #[Test]
    public function aPendingOrderCardShowsRecomputedNetCostsAndAVerifyLink(): void
    {
        [, , $bob] = Tables::aliceAndBob($this->entityManager);
        OrderBuilder::for($bob)->withKeys('pottery')->persist($this->entityManager);

        $crawler = $this->createPlayerOrders($bob)->render()->crawler();
        $card = $crawler->filter('article')->first();

        $this->assertSame('pending', $card->attr('data-status'));
        $this->assertStringContainsString('Pottery', $card->text());
        $this->assertStringContainsString('Total: 60', $card->text());
        $this->assertStringContainsString('VP: 1', $card->text());
        $this->assertSame('Verify', trim($card->filter('a')->first()->text()));
    }

    #[Test]
    public function pastTurnsWithNoOrderStayEmptyWhileTheCurrentTurnIsPending(): void
    {
        [$game, , $bob] = Tables::aliceAndBob($this->entityManager);
        $game->currentTurn = 3;
        $this->entityManager->flush();
        OrderBuilder::for($bob)->withKeys('pottery')->persist($this->entityManager);

        $cards = $this->createPlayerOrders($bob)->render()->crawler()->filter('article');

        $this->assertSame('pending', $cards->eq(0)->attr('data-status'));

        $this->assertSame('empty', $cards->eq(1)->attr('data-status'));
        $this->assertSame('Edit', trim($cards->eq(1)->filter('a')->first()->text()));

        $this->assertSame('empty', $cards->eq(2)->attr('data-status'));
        $this->assertSame('Edit', trim($cards->eq(2)->filter('a')->first()->text()));
    }

    #[Test]
    public function aValidatedOrderCardShowsFrozenNetCostsAndSwapsTheTillLinkForTheEraseModal(): void
    {
        [, , $bob] = Tables::aliceAndBob($this->entityManager);
        $this->validateOrderFor($bob, ['democracy', 'pottery']);

        $crawler = $this->createPlayerOrders($bob)->render()->crawler();
        $card = $crawler->filter('article')->first();

        $this->assertSame('validated', $card->attr('data-status'));
        $this->assertStringContainsString('Democracy', $card->text());
        $this->assertStringContainsString('Pottery', $card->text());
        $this->assertStringContainsString('Total: 280', $card->text());
        $this->assertStringContainsString('VP: 7', $card->text());
        $this->assertSame('Empty', trim($card->filter('button')->first()->text()));
        $this->assertCount(1, $card->filter('button[commandfor]'));
        $this->assertCount(0, $card->filter('a[href$="/operator/pos"]'));
    }

    #[Test]
    public function eraseConfirmForTheCurrentTurnHasNoCascadeMention(): void
    {
        [$game, $alice] = Tables::aliceAndBob($this->entityManager);
        $game->currentTurn = 1;
        $this->entityManager->flush();
        $this->validateOrderFor($alice, ['democracy']);

        $rendered = $this->createPlayerOrders($alice)->render()->toString();

        $this->assertStringContainsString('Empty turn 1?', $rendered);
        $this->assertStringNotContainsString('also empty turn', $rendered);
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
