<?php

declare(strict_types=1);

namespace App\Tests\Component;

use App\State\Game;
use App\Tests\Support\Fixture\GameBuilder;
use App\Tests\Support\GameFixtureTrait;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\UX\LiveComponent\Test\InteractsWithLiveComponents;

final class GameHeadingTest extends WebTestCase
{
    use GameFixtureTrait;
    use InteractsWithLiveComponents;

    #[Test]
    public function theHeadingIsTitledWithTheGameAndItsTurn(): void
    {
        $game = GameBuilder::create()->withCurrentTurn(4)->persist($this->entityManager);

        $crawler = $this->render($game);

        $this->assertCount(1, $crawler->filter('header#page-title[data-title="page"] > hgroup'));
        $this->assertSame($game->slug, trim($crawler->filter('#page-title hgroup > h1')->text()));
        $this->assertSame('Turn 4', trim($crawler->filter('#page-title hgroup > p')->text()));
    }

    #[Test]
    public function aFinishedGameSaysSoInTheQualifier(): void
    {
        $game = GameBuilder::create()->withCurrentTurn(4)->finished()->persist($this->entityManager);

        $crawler = $this->render($game);

        $this->assertSame('Turn 4 — finished', trim($crawler->filter('#page-title hgroup > p')->text()));
    }

    #[Test]
    public function theHeadingRefreshesOnTheOperatorTopic(): void
    {
        $game = GameBuilder::create()->persist($this->entityManager);

        $crawler = $this->render($game);

        $this->assertSame(
            'empires/game/'.$game->id.'/operator',
            $crawler->filter('div[data-controller~="mercure-refresh"]')->attr('data-mercure-refresh-topic-value'),
        );
    }

    private function render(Game $game): Crawler
    {
        return $this->createLiveComponent('molecules:GameHeading', ['game' => $game])->render()->crawler();
    }
}
