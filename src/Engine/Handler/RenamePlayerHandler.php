<?php

declare(strict_types=1);

namespace App\Engine\Handler;

use App\Engine\Event\PlayerUpdated;
use App\Rules\Action\RenamePlayer;
use App\State\Repository\PlayerRepositoryInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * The name is already normalised and validated (blank, length, per-game uniqueness) by the
 * dispatching component — this handler only writes what it is given. A request equal to the
 * player's current name is a no-op, mirroring SetStatHandler's guard: not for data integrity
 * (re-writing an identical slug on the same row violates nothing), but to spare a pointless
 * UPDATE and, above all, a Mercure ping that would refresh every board in the game for nothing.
 */
#[AsMessageHandler]
final readonly class RenamePlayerHandler
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private PlayerRepositoryInterface $playerRepository,
        private MessageBusInterface $eventBus,
    ) {}

    public function __invoke(RenamePlayer $command): void
    {
        $player = $this->playerRepository->findById($command->playerId) ?? throw new \RuntimeException('Player not found.');

        if ($player->name === $command->name) {
            return;
        }

        $player->name = $command->name;
        $this->entityManager->flush();

        $this->eventBus->dispatch(new PlayerUpdated($player->id));
    }
}
