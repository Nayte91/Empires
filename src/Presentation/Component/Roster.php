<?php

declare(strict_types=1);

namespace App\Presentation\Component;

use App\Rules\CensusOrderCalculator;
use App\State\Game;
use App\State\Player;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\DefaultActionTrait;

/**
 * Who plays when, and what each of them holds. Read-only, re-rendered by a
 * Mercure ping whenever the operator console changes the game.
 */
#[AsLiveComponent(template: 'molecules/roster.html.twig')]
final class Roster
{
    use DefaultActionTrait;

    #[LiveProp]
    public Game $game; // @phpstan-ignore property.uninitialized (hydrated by LiveComponent via reflection before use)

    public function __construct(private readonly CensusOrderCalculator $censusOrderCalculator) {}

    /**
     * The rows in play order: the table is read down the movement-phase turn order. The score
     * itself lives on the A.S.T. board, where the track already says what a position is worth.
     *
     * @return list<array{player: Player, rank: int, military: bool}>
     */
    public function getPlayerRows(): array
    {
        $players = $this->censusOrderCalculator->orderFor($this->game);

        return array_map(
            fn (Player $player, int $index): array => [
                'player' => $player,
                'rank' => $index + 1,
                'military' => $this->censusOrderCalculator->hasMilitary($player),
            ],
            $players,
            array_keys($players),
        );
    }
}
