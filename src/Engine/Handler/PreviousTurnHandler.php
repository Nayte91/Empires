<?php

declare(strict_types=1);

namespace App\Engine\Handler;

use App\Engine\Event\GameUpdated;
use App\Infrastructure\Repository\GameRepository;
use App\Rules\Action\PreviousTurn;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

/** The floor-side twin of {@see NextTurnHandler}: refuses to go below turn 1 or to move a finished game. */
#[AsMessageHandler]
final readonly class PreviousTurnHandler
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private GameRepository $gameRepository,
        private MessageBusInterface $eventBus,
    ) {}

    public function __invoke(PreviousTurn $command): void
    {
        $game = $this->gameRepository->find($command->gameId) ?? throw new \RuntimeException('Game not found.');

        if ($game->finished) {
            return;
        }

        if ($game->currentTurn <= 1) {
            return;
        }

        --$game->currentTurn;
        $this->entityManager->flush();

        $this->eventBus->dispatch(new GameUpdated($game->id));
    }
}
