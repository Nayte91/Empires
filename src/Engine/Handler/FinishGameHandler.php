<?php

declare(strict_types=1);

namespace App\Engine\Handler;

use App\Engine\Event\GameUpdated;
use App\Rules\Action\FinishGame;
use App\State\Repository\GameRepositoryInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsMessageHandler]
final readonly class FinishGameHandler
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private GameRepositoryInterface $gameRepository,
        private MessageBusInterface $eventBus,
    ) {}

    public function __invoke(FinishGame $command): void
    {
        $game = $this->gameRepository->findById($command->gameId) ?? throw new \RuntimeException('Game not found.');

        if ($game->finished) {
            return;
        }

        $game->finishedAt = new \DateTimeImmutable();
        $this->entityManager->flush();

        $this->eventBus->dispatch(new GameUpdated($game->id));
    }
}
