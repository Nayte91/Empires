<?php

declare(strict_types=1);

namespace App\Tests\Component;

use App\Infrastructure\Repository\OrderRepository;
use App\State\Game;
use App\State\Order;
use App\State\Player;
use App\Tests\Support\Fixture\GameBuilder;
use App\Tests\Support\Fixture\PlayerBuilder;
use App\Tests\Support\GameFixtureTrait;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\UX\LiveComponent\Test\InteractsWithLiveComponents;
use Symfony\UX\LiveComponent\Test\TestLiveComponent;
use Userforged\ShopEngine\Dto\OrderLine;
use Userforged\ShopEngine\Service\OrderValidator;

final class PlayerOrdersTest extends WebTestCase
{
    use GameFixtureTrait;
    use InteractsWithLiveComponents;

    #[Test]
    public function eraseOrderCascadesRemovingLaterTurnsAndDisowningAdvances(): void
    {
        [$game, $alice] = $this->createGameWithAliceAndBob();
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
        [$game, , $bob] = $this->createGameWithAliceAndBob();
        $game->currentTurn = 3;
        $this->entityManager->flush();
        $this->createPendingOrderFor($bob, ['pottery']);

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
        [$game, , $bob] = $this->createGameWithAliceAndBob();

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
        [, , $bob] = $this->createGameWithAliceAndBob();
        $this->createPendingOrderFor($bob, ['pottery']);

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
        [$game, , $bob] = $this->createGameWithAliceAndBob();
        $game->currentTurn = 3;
        $this->entityManager->flush();
        $this->createPendingOrderFor($bob, ['pottery']);

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
        [, , $bob] = $this->createGameWithAliceAndBob();
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
        [$game, $alice] = $this->createGameWithAliceAndBob();
        $game->currentTurn = 1;
        $this->entityManager->flush();
        $this->validateOrderFor($alice, ['democracy']);

        $rendered = $this->createPlayerOrders($alice)->render()->toString();

        $this->assertStringContainsString('Empty turn 1?', $rendered);
        $this->assertStringNotContainsString('also empty turn', $rendered);
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

    private function createPlayerOrders(Player $player): TestLiveComponent
    {
        return $this->createLiveComponent('PlayerOrders', ['player' => $player, 'ordersStamp' => '']);
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
