<?php

declare(strict_types=1);

namespace App\Tests\Component;

use App\State\Game;
use App\Tests\Support\Fixture\GameBuilder;
use App\Tests\Support\Fixture\PlayerBuilder;
use App\Tests\Support\Fixture\Tables;
use App\Tests\Support\GameFixtureTrait;
use App\Tests\Support\ShopFixtureTrait;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\UX\LiveComponent\Test\InteractsWithLiveComponents;

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
    public function everyPlayerAtTheTableGetsASection(): void
    {
        $game = Tables::westTable($this->entityManager);

        $sections = $this->renderOrders($game)->filter('section[data-player-id]');

        $this->assertCount($game->players->count(), $sections);
    }

    #[Test]
    public function ordersStampForChangesWhenTheCurrentTurnChangesEvenWithZeroOrders(): void
    {
        $game = GameBuilder::create()->persist($this->entityManager);
        $player = PlayerBuilder::named('Alice')->in($game)->withEmpire('minoa')->persist($this->entityManager);

        $orders = $this->createLiveComponent('OperatorOrders', ['game' => $game])->component();
        $stampAtTurnOne = $orders->ordersStampFor($player);

        $game->currentTurn = 2;
        $this->entityManager->flush();

        $stampAtTurnTwo = $orders->ordersStampFor($this->reloadPlayer($player));

        $this->assertNotSame($stampAtTurnOne, $stampAtTurnTwo);
    }

    private function renderOrders(Game $game): Crawler
    {
        return $this->createLiveComponent('OperatorOrders', ['game' => $game])->render()->crawler();
    }
}
