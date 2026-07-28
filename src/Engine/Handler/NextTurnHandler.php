<?php

declare(strict_types=1);

namespace App\Engine\Handler;

use App\Engine\Event\GameUpdated;
use App\Infrastructure\Repository\GameRepository;
use App\Rules\Action\NextTurn;
use App\Rules\Ruleset\GameRegistry;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Bounds the turn counter at the game's own `max_turns` limit and refuses to advance a finished
 * game — guards that used to live in OperatorConsole, moved here so a raw dispatch on the bus can
 * never push a game past legality, whatever component (or lack of one) asked for it.
 */
#[AsMessageHandler]
final readonly class NextTurnHandler
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private GameRepository $gameRepository,
        private GameRegistry $gameRegistry,
        private MessageBusInterface $eventBus,
    ) {}

    public function __invoke(NextTurn $command): void
    {
        $game = $this->gameRepository->find($command->gameId) ?? throw new \RuntimeException('Game not found.');

        if ($game->finished) {
            return;
        }

        if ($game->currentTurn >= $this->maxTurns()) {
            return;
        }

        ++$game->currentTurn;
        $this->entityManager->flush();

        $this->eventBus->dispatch(new GameUpdated($game->id));
    }

    private function maxTurns(): int
    {
        return $this->gameRegistry->getLimits()['max_turns'] ?? 20;
    }
}
