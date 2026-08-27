<?php

declare(strict_types=1);

namespace App\Presentation\Component;

use App\Rules\CensusOrderCalculator;
use App\State\Game;
use App\State\Player;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

#[AsTwigComponent(template: 'molecules/roster.html.twig')]
final class Roster
{
    public Game $game; // @phpstan-ignore property.uninitialized (hydrated by TwigComponent via reflection before use)

    public function __construct(private readonly CensusOrderCalculator $censusOrderCalculator) {}

    /** @return list<array{player: Player, rank: int, military: bool}> */
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
