<?php

declare(strict_types=1);

namespace App\Presentation\Component;

use App\Rules\CensusOrderCalculator;
use App\Rules\Ruleset\EmpireRegistry;
use App\State\Game;
use App\State\Player;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\DefaultActionTrait;

/**
 * Read-only display of player scores and achievements. Re-rendered by a
 * Mercure ping whenever the operator console changes the game.
 */
#[AsLiveComponent(template: 'molecules/scoreBoard.html.twig')]
final class ScoreBoard
{
    use DefaultActionTrait;

    #[LiveProp]
    public Game $game; // @phpstan-ignore property.uninitialized (hydrated by LiveComponent via reflection before use)

    public function __construct(
        private readonly EmpireRegistry $empireRegistry,
        private readonly CensusOrderCalculator $censusOrderCalculator,
    ) {}

    /**
     * The rows in play order: the table is read down the movement-phase turn order. The score
     * itself lives on the A.S.T. board, where the track already says what a position is worth.
     *
     * @return list<array{player: Player, military: bool}>
     */
    public function getPlayerRows(): array
    {
        return array_map(
            fn (Player $player): array => [
                'player' => $player,
                'military' => $this->censusOrderCalculator->hasMilitary($player),
            ],
            $this->censusOrderCalculator->orderFor($this->game),
        );
    }

    public function empireAdjective(Player $player): ?string
    {
        return null === $player->empire ? null : $this->empireRegistry->findByName($player->empire)?->adjective;
    }
}
