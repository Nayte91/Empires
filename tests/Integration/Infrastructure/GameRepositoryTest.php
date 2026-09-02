<?php

declare(strict_types=1);

namespace App\Tests\Integration\Infrastructure;

use App\Infrastructure\Repository\GameRepository;
use App\Tests\Support\GameFixtureTrait;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use App\Tests\Support\Fixture\GameBuilder;

final class GameRepositoryTest extends WebTestCase
{
    use GameFixtureTrait;

    private GameRepository $gameRepository;

    protected function setUp(): void
    {
        $this->initEntityManager();

        $this->gameRepository = self::getContainer()->get(GameRepository::class);
    }

    #[Test]
    public function persistedGameIsFoundWithItsDefaultValues(): void
    {
        $game = GameBuilder::create()->persist($this->entityManager);

        $foundGame = $this->gameRepository->find($game->id);

        $this->assertInstanceOf(\App\State\Game::class, $foundGame);
        $this->assertSame($game->id->toRfc4122(), $foundGame->id->toRfc4122());
        $this->assertSame(1, $foundGame->currentTurn);
        $this->assertSame(9, $foundGame->playerCount);
    }

    #[Test]
    public function gameTableIsEmptyAtTheStartOfEachTest(): void
    {
        $this->assertSame(0, $this->gameRepository->count([]));
    }

    #[Test]
    public function findInProgressReturnsUnfinishedGamesOrderedFromMostToLeastRecent(): void
    {
        $oldest = GameBuilder::create()->persist($this->entityManager);
        $middle = GameBuilder::create()->persist($this->entityManager);
        GameBuilder::create()->finished()->persist($this->entityManager);

        $games = $this->gameRepository->findInProgress();

        $this->assertCount(2, $games);
        $this->assertSame($middle->id->toRfc4122(), $games[0]->id->toRfc4122());
        $this->assertSame($oldest->id->toRfc4122(), $games[1]->id->toRfc4122());
    }
}
