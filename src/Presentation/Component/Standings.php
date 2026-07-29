<?php

declare(strict_types=1);

namespace App\Presentation\Component;

use App\Rules\CensusOrderCalculator;
use App\Rules\Ruleset\AstRegistry;
use App\Rules\StandingsCalculator;
use App\State\Game;
use App\State\Player;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\DefaultActionTrait;

/**
 * The phone's reading of the table: one row per player, the score alone, everything else a tap
 * away. The wide dashboard keeps {@see Roster}'s columns — this is the same facts, ordered
 * by standing rather than by movement, because a phone shows six rows at a time and the question
 * it answers is "who is winning", not "who plays next".
 */
#[AsLiveComponent(template: 'molecules/standings.html.twig')]
final class Standings
{
    use DefaultActionTrait;

    #[LiveProp]
    public Game $game; // @phpstan-ignore property.uninitialized (hydrated by LiveComponent via reflection before use)

    public function __construct(
        private readonly CensusOrderCalculator $censusOrderCalculator,
        private readonly StandingsCalculator $standingsCalculator,
        private readonly AstRegistry $astRegistry,
    ) {}

    /**
     * Sorted by score, leader first, ties left in movement order — PHP's sort is stable, so
     * starting from the movement order is what settles a draw. Two players on the same score are
     * separated by who plays first, which is the only tie-break the table can state without
     * inventing one.
     *
     * @return list<array{player: Player, rank: int, score: int, band: string, advances: int}>
     */
    public function getRows(): array
    {
        $players = $this->censusOrderCalculator->orderFor($this->game);
        usort($players, fn (Player $a, Player $b): int => $this->standingsCalculator->scoreOf($b) <=> $this->standingsCalculator->scoreOf($a));

        return array_values(array_map(
            fn (Player $player, int $index): array => [
                'player' => $player,
                'rank' => $index + 1,
                'score' => $this->standingsCalculator->scoreOf($player),
                'band' => $this->bandOf($player),
                'advances' => \count($player->advances),
            ],
            $players,
            array_keys($players),
        ));
    }

    /** The band is stored state, read off the A.S.T. position — never inferred from the score. */
    private function bandOf(Player $player): string
    {
        $group = $this->astRegistry->resolveEmpireGroup($this->game->astVersion, $player->empire);

        return $this->astRegistry->getEraForPosition($player->astPosition, $this->game->astVersion, $group)->name;
    }
}
