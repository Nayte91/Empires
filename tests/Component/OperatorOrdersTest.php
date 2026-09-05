<?php

declare(strict_types=1);

namespace App\Tests\Component;

use App\Infrastructure\Repository\OrderRepository;
use App\State\Game;
use App\State\Order;
use App\State\Player;
use App\Tests\Support\Fixture\GameBuilder;
use App\Tests\Support\Fixture\OrderBuilder;
use App\Tests\Support\Fixture\Tables;
use App\Tests\Support\GameFixtureTrait;
use App\Tests\Support\ShopFixtureTrait;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\UX\LiveComponent\Test\InteractsWithLiveComponents;
use Symfony\UX\LiveComponent\Test\TestLiveComponent;
use Userforged\ShopEngine\Service\OrderValidator;

final class OperatorOrdersTest extends WebTestCase
{
    use GameFixtureTrait;
    use InteractsWithLiveComponents;
    use ShopFixtureTrait;

    #[Test]
    public function theOrdersPageListensOnItsOwnTopic(): void
    {
        $game = GameBuilder::create()->persist($this->entityManager);

        $rendered = $this->renderOrders($game);

        $this->assertSame(
            'empires/game/'.$game->id.'/operator',
            $rendered->filter('[data-mercure-refresh-topic-value]')->attr('data-mercure-refresh-topic-value'),
        );
    }

    #[Test]
    public function everyPlayerAtTheTableGetsACardForTheCurrentTurn(): void
    {
        $game = Tables::westTable($this->entityManager);

        $cards = $this->createOrders($game)->set('filter', 'all')->component()->getCards();

        $this->assertCount($game->players->count(), array_filter(
            $cards,
            static fn (array $card): bool => $card['turn'] === $game->currentTurn,
        ));
    }

    #[Test]
    public function theFilterTheOperatorLandsOnShowsOnlyWhatAwaitsThem(): void
    {
        $game = Tables::westTable($this->entityManager);
        OrderBuilder::for(Tables::seat($game, 'Alice'))->withKeys('democracy')->validated()->persist($this->entityManager);

        $cards = $this->createOrders($game)->component()->getCards();

        $this->assertSame([], $cards);
    }

    #[Test]
    public function aFilterWithNothingToShowRendersAZeroCard(): void
    {
        $game = Tables::westTable($this->entityManager);

        $rendered = $this->renderOrders($game);

        $this->assertCount(1, $rendered->filter('article[data-status="none"]'));
    }

    #[Test]
    public function theMissingFilterKeepsEveryPlayerWhoHasNotOrderedThisTurn(): void
    {
        $game = Tables::westTable($this->entityManager);
        OrderBuilder::for(Tables::seat($game, 'Alice'))->withKeys('democracy')->validated()->persist($this->entityManager);

        $cards = $this->createOrders($game)->set('filter', 'missing')->component()->getCards();

        $this->assertCount($game->players->count() - 1, $cards);
    }

    #[Test]
    public function theCountsReadTheCurrentTurnOnly(): void
    {
        [$game, $alice, $bob] = Tables::aliceAndBob($this->entityManager);
        OrderBuilder::for($alice)->onTurn(1)->withKeys('democracy')->validated()->persist($this->entityManager);
        $game->currentTurn = 2;
        $this->entityManager->flush();
        OrderBuilder::for($bob)->onTurn(2)->withKeys('pottery')->persist($this->entityManager);

        $counts = $this->createOrders($game)->component()->getCounts();

        $this->assertSame(['pending' => 1, 'validated' => 0, 'missing' => 1], $counts);
    }

    #[Test]
    public function erasingAnOrderCascadesOverTheLaterTurnsAndDisownsTheirAdvances(): void
    {
        [$game, $alice] = Tables::aliceAndBob($this->entityManager);
        $this->validateOrderFor($alice, ['democracy']);

        $game->currentTurn = 2;
        $this->entityManager->flush();
        $this->validateOrderFor($alice, ['pottery']);

        $this->createOrders($game)->call('eraseOrder', ['playerId' => (string) $alice->id, 'turn' => 1]);

        $this->assertNotInstanceOf(Order::class, $this->freshOrderRepository()->findOneByPlayerAndWindow($alice, 1));
        $this->assertNotInstanceOf(Order::class, $this->freshOrderRepository()->findOneByPlayerAndWindow($alice, 2));

        $reloadedAlice = $this->reloadPlayer($alice);
        $this->assertNotContains('democracy', $reloadedAlice->advances);
        $this->assertNotContains('pottery', $reloadedAlice->advances);
        $this->assertContains('agriculture', $reloadedAlice->advances);
    }

    #[Test]
    public function aValidatedCardSwapsTheTillLinkForTheEraseModal(): void
    {
        [$game, , $bob] = Tables::aliceAndBob($this->entityManager);
        $this->validateOrderFor($bob, ['democracy', 'pottery']);

        $rendered = $this->createOrders($game)->set('filter', 'all')->render()->crawler();

        $card = \sprintf('article[data-player-id="%s"][data-turn="1"]', $bob->id);
        $this->assertCount(1, $rendered->filter($card.' button[command="show-modal"]'));
        $this->assertCount(0, $rendered->filter($card.' a[href*="/operator/pos"]'));
    }

    private function createOrders(Game $game): TestLiveComponent
    {
        return $this->createLiveComponent('OperatorOrders', ['game' => $game]);
    }

    private function renderOrders(Game $game): Crawler
    {
        return $this->createOrders($game)->render()->crawler();
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
