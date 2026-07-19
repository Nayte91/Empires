<?php

declare(strict_types=1);

namespace App\Game\CommandHandler;

use App\Entity\GameSession;
use App\Entity\Player;
use App\Game\Command\CreateGame;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class CreateGameHandler
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {}

    public function __invoke(CreateGame $command): void
    {
        $this->entityManager->wrapInTransaction(function () use ($command): void {
            $game = new GameSession($command->slug);
            $game->playerCount = $command->playerCount;
            $game->region = $command->region;
            $game->setAstVersionValue($command->astVersion);

            $this->entityManager->persist($game);

            foreach ($command->players as $playerData) {
                $player = new Player($game, $playerData['name']);
                $player->empire = $playerData['empire'];
                $this->entityManager->persist($player);
            }
        });
    }
}
