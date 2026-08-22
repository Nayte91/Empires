<?php

declare(strict_types=1);

namespace App\Engine\Handler;

use App\Engine\Event\PlayerUpdated;
use App\Rules\Action\ApplyStatAction;
use App\Rules\HandSizeCalculator;
use App\Rules\StatBoundsCalculator;
use App\Rules\TaxCalculator;
use App\State\Repository\PlayerRepositoryInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * The action name travels as a client-writable LiveProp (StatPicker::$pendingAction), so this is
 * where legality is actually enforced: an action must be both offered (isOffered — on the
 * player's menu at all) and available (isAvailable — clickable right now), or it silently no-ops.
 * StatPicker::runAction() used to check only isOffered() via getActions(); an offered-but-
 * unavailable action (e.g. building a ship with less than its cost in treasury) would still
 * apply(), clamped but not refused.
 */
#[AsMessageHandler]
final readonly class ApplyStatActionHandler
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private PlayerRepositoryInterface $playerRepository,
        private HandSizeCalculator $handSizeCalculator,
        private StatBoundsCalculator $statBoundsCalculator,
        private TaxCalculator $taxCalculator,
        private MessageBusInterface $eventBus,
    ) {}

    public function __invoke(ApplyStatAction $command): void
    {
        $player = $this->playerRepository->findById($command->playerId) ?? throw new \RuntimeException('Player not found.');
        $action = $command->action;

        if (!$action->isOffered($player, $this->taxCalculator)) {
            return;
        }

        if (!$action->isAvailable($player, $this->handSizeCalculator, $this->statBoundsCalculator, $this->taxCalculator)) {
            return;
        }

        $action->apply($player, $this->handSizeCalculator, $this->statBoundsCalculator, $this->taxCalculator);
        $this->entityManager->flush();

        $this->eventBus->dispatch(new PlayerUpdated($player->id));
    }
}
