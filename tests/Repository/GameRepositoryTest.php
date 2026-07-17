<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\Game;
use App\Repository\GameRepository;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class GameRepositoryTest extends WebTestCase
{
    private EntityManagerInterface $entityManager;
    private GameRepository $gameRepository;

    protected function setUp(): void
    {
        self::bootKernel();

        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->gameRepository = self::getContainer()->get(GameRepository::class);
    }

    #[Test]
    public function persistedGameIsFoundWithItsDefaultValues(): void
    {
        $game = new Game();

        $this->entityManager->persist($game);
        $this->entityManager->flush();

        $foundGame = $this->gameRepository->find($game->id);

        self::assertNotNull($foundGame);
        self::assertSame($game->id->toRfc4122(), $foundGame->id->toRfc4122());
        self::assertSame(1, $foundGame->currentTurn);
        self::assertSame(9, $foundGame->playerCount);
    }

    #[Test]
    public function gameTableIsEmptyAtTheStartOfEachTest(): void
    {
        self::assertSame(0, $this->gameRepository->count([]));
    }

    #[Test]
    public function setCurrentTurnClampsToTheOneToTwentyRange(): void
    {
        $game = new Game();

        $game->currentTurn = 0;
        self::assertSame(1, $game->currentTurn);

        $game->currentTurn = 25;
        self::assertSame(20, $game->currentTurn);
    }

    #[Test]
    public function findInProgressReturnsUnfinishedGamesOrderedFromMostToLeastRecent(): void
    {
        $oldest = new Game();
        $this->entityManager->persist($oldest);
        $this->entityManager->flush();

        $middle = new Game();
        $this->entityManager->persist($middle);
        $this->entityManager->flush();

        $finished = new Game();
        $finished->finishedAt = new \DateTimeImmutable();
        $this->entityManager->persist($finished);
        $this->entityManager->flush();

        $games = $this->gameRepository->findInProgress();

        self::assertCount(2, $games);
        self::assertSame($middle->id->toRfc4122(), $games[0]->id->toRfc4122());
        self::assertSame($oldest->id->toRfc4122(), $games[1]->id->toRfc4122());
    }
}
