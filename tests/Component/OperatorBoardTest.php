<?php

declare(strict_types=1);

namespace App\Tests\Component;

use App\State\Game;
use App\Tests\Support\Fixture\GameBuilder;
use App\Tests\Support\Fixture\PlayerBuilder;
use App\Tests\Support\GameFixtureTrait;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\UX\LiveComponent\Test\InteractsWithLiveComponents;

final class OperatorBoardTest extends WebTestCase
{
    use GameFixtureTrait;
    use InteractsWithLiveComponents;

    #[Test]
    public function theBoardListensOnItsOwnTopic(): void
    {
        $game = GameBuilder::create()->persist($this->entityManager);

        $rendered = $this->renderBoard($game);

        $this->assertSame(
            'empires/game/'.$game->id.'/operator',
            $rendered->filter('[data-mercure-refresh-topic-value]')->attr('data-mercure-refresh-topic-value'),
        );
    }

    #[Test]
    public function theBoardEmbedsAControlTableHoldingOnePickerPerTrackedStatOfEveryPlayer(): void
    {
        $game = GameBuilder::create()->persist($this->entityManager);
        PlayerBuilder::named('Alice')->in($game)->withEmpire('minoa')->persist($this->entityManager);
        PlayerBuilder::named('Bob')->in($game)->withEmpire('rome')->persist($this->entityManager);

        $crawler = $this->renderBoard($game);

        $this->assertCount(6, $crawler->filter('dialog[id^="stat-picker-"]'));
    }

    #[Test]
    public function aFinishedGameOffersNeitherControlTableNorTurnControl(): void
    {
        $game = GameBuilder::create()->finished()->persist($this->entityManager);
        PlayerBuilder::named('Alice')->in($game)->withEmpire('minoa')->persist($this->entityManager);

        $crawler = $this->renderBoard($game);

        $this->assertCount(0, $crawler->filter('table'));
        $this->assertCount(0, $crawler->filter('button'));
    }

    #[Test]
    public function theBoardRendersNoDuplicatedElementId(): void
    {
        $game = GameBuilder::create()->persist($this->entityManager);
        PlayerBuilder::named('Alice')->in($game)->withEmpire('minoa')->persist($this->entityManager);
        PlayerBuilder::named('Bob')->in($game)->withEmpire('rome')->persist($this->entityManager);

        $crawler = $this->renderBoard($game);

        $ids = $crawler->filter('[id]')->each(static fn (Crawler $node): string => (string) $node->attr('id'));

        $ids
            |> array_unique(...)
            |> (fn($x): array => array_diff_assoc($ids, $x))
            |> array_unique(...)
            |> array_values(...)
            |> (fn($x) => $this->assertSame([], $x));
    }

    #[Test]
    public function finishGameFillsInFinishedAt(): void
    {
        $game = GameBuilder::create()->persist($this->entityManager);

        $this->createLiveComponent('OperatorBoard', ['game' => $game])->call('finishGame');

        $this->assertInstanceOf(\DateTimeImmutable::class, $this->reloadGame($game)->finishedAt);
    }

    #[Test]
    public function nextTurnAdvancesTheGameByOneTurn(): void
    {
        $game = GameBuilder::create()->persist($this->entityManager);

        $this->createLiveComponent('OperatorBoard', ['game' => $game])->call('nextTurn');

        $this->assertSame(2, $this->reloadGame($game)->currentTurn);
    }

    #[Test]
    public function previousTurnRewindsTheGameByOneTurn(): void
    {
        $game = GameBuilder::create()->withCurrentTurn(2)->persist($this->entityManager);

        $this->createLiveComponent('OperatorBoard', ['game' => $game])->call('previousTurn');

        $this->assertSame(1, $this->reloadGame($game)->currentTurn);
    }

    private function renderBoard(Game $game): Crawler
    {
        return $this->createLiveComponent('OperatorBoard', ['game' => $game])->render()->crawler();
    }

    private function freshEntityManager(): EntityManagerInterface
    {
        return self::getContainer()->get(EntityManagerInterface::class);
    }

    private function reloadGame(Game $game): Game
    {
        $reloaded = $this->freshEntityManager()->find(Game::class, $game->id);
        $this->assertInstanceOf(Game::class, $reloaded);

        return $reloaded;
    }
}
