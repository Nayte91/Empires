<?php

declare(strict_types=1);

namespace App\Engine\Handler;

use App\Rules\Action\CreateGame;
use App\Rules\Ruleset\ScenarioRegistry;
use App\State\CreditEntry;
use App\State\CreditSource;
use App\State\Game;
use App\State\Player;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Where a game is calibrated: empires and starting hands are assigned to
 * each player here, so the scenario's starting credits are posted to the
 * same place, on the append-only credit ledger (Player::postCredit()), at
 * turn 0. Storing rather than deriving from config/game/scenarios.yaml
 * freezes what a game actually started with, immune to later catalog edits.
 */
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
            $game = new Game($command->slug);
            $game->playerCount = $command->playerCount;
            $game->region = $command->region;
            $game->setAstVersionValue($command->astVersion);

            $this->entityManager->persist($game);

            $startingCredits = $this->scenarioRegistry->startingCreditsFor($command->playerCount);
            $reason = 'scenario:'.$command->playerCount;

            foreach ($command->players as $playerData) {
                $player = new Player($game, $playerData['name']);
                $player->empire = $playerData['empire'];

                foreach ($startingCredits as $scope => $value) {
                    $player->postCredit(new CreditEntry(self::STARTING_CREDITS_TURN, $scope, $value, CreditSource::Scenario, $reason));
                }

                $this->entityManager->persist($player);
            }
        });
    }
}
