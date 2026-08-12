<?php

declare(strict_types=1);

namespace App\Engine\Handler;

use App\Rules\Action\CreateGame;
use App\Rules\Ruleset\ScenarioRegistry;
use App\State\ASTVersion;
use App\State\CreditEntry;
use App\State\CreditSource;
use App\State\Game;
use App\State\Player;
use App\State\Region;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class CreateGameHandler
{
    private const int STARTING_CREDITS_TURN = 0;

    public function __construct(
        private EntityManagerInterface $entityManager,
        private ScenarioRegistry $scenarioRegistry,
    ) {}

    public function __invoke(CreateGame $command): void
    {
        $this->entityManager->wrapInTransaction(function () use ($command): void {
            $region = null === $command->region ? null : Region::from($command->region);

            $game = new Game($command->slug);
            $game->playerCount = $command->playerCount;
            $game->region = $region;
            $game->astVersion = ASTVersion::from($command->astVersion);

            $this->entityManager->persist($game);

            $startingCredits = $this->scenarioRegistry->find($command->playerCount, $region)->startingCredits ?? [];
            $reason = 'scenario:'.$command->playerCount;

            foreach ($command->players as $playerData) {
                $player = new Player($game, $playerData['name'], $playerData['empire']);

                foreach ($startingCredits as $scope => $value) {
                    $player->postCredit(new CreditEntry(self::STARTING_CREDITS_TURN, $scope, $value, CreditSource::Scenario, $reason));
                }

                $this->entityManager->persist($player);
            }
        });
    }
}
