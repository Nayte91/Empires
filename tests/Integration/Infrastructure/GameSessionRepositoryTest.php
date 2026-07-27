<?php

declare(strict_types=1);

namespace App\Tests\Integration\Infrastructure;

use App\State\GameSession;
use App\Infrastructure\Repository\GameSessionRepository;
use App\Tests\Support\GameFixtureTrait;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class GameSessionRepositoryTest extends WebTestCase
{
    use GameFixtureTrait;

    private GameSessionRepository $gameRepository;

    protected function setUp(): void
    {
        $this->initEntityManager();

        $this->gameRepository = self::getContainer()->get(GameSessionRepository::class);
    }

    #[Test]
    public function persistedGameIsFoundWithItsDefaultValues(): void
    {
        $game = new GameSession();

        $this->entityManager->persist($game);
        $this->entityManager->flush();

        $foundGame = $this->gameRepository->find($game->id);

        $this->assertInstanceOf(\App\State\GameSession::class, $foundGame);
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
        $oldest = new GameSession();
        $this->entityManager->persist($oldest);
        $this->entityManager->flush();

        $middle = new GameSession();
        $this->entityManager->persist($middle);
        $this->entityManager->flush();

        $finished = new GameSession();
        $finished->finishedAt = new \DateTimeImmutable();
        $this->entityManager->persist($finished);
        $this->entityManager->flush();

        $games = $this->gameRepository->findInProgress();

        $this->assertCount(2, $games);
        $this->assertSame($middle->id->toRfc4122(), $games[0]->id->toRfc4122());
        $this->assertSame($oldest->id->toRfc4122(), $games[1]->id->toRfc4122());
    }
}
