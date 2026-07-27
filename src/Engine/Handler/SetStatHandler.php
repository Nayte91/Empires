<?php

declare(strict_types=1);

namespace App\Engine\Handler;

use App\Engine\Event\PlayerUpdated;
use App\Infrastructure\Repository\PlayerRepository;
use App\Rules\Action\SetStat;
use App\Rules\StatBoundsCalculator;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Clamps the requested value to [floorFor, ceilingFor] before writing it — the bound StatPicker's
 * own grid enforces on the client, restated here because `value` travels as a client-writable
 * LiveProp. A request equal to the stat's current, unclamped value is a no-op: this mirrors
 * StatPicker::save()'s prior guard exactly, comparing against the raw requested value rather than
 * its clamped target.
 */
#[AsMessageHandler]
final readonly class SetStatHandler
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private PlayerRepository $playerRepository,
        private StatBoundsCalculator $statBoundsCalculator,
        private MessageBusInterface $eventBus,
    ) {}

    public function __invoke(SetStat $command): void
    {
        $player = $this->playerRepository->find($command->playerId) ?? throw new \RuntimeException('Player not found.');

        if ($command->stat->read($player) === $command->value) {
            return;
        }

        $floor = $this->statBoundsCalculator->floorFor($player, $command->stat);
        $ceiling = $this->statBoundsCalculator->ceilingFor($player, $command->stat);

        $command->stat->write($player, max($floor, min($command->value, $ceiling)));
        $this->entityManager->flush();

        $this->eventBus->dispatch(new PlayerUpdated($player->id));
    }
}
