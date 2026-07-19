<?php

declare(strict_types=1);

namespace App\Component;

use App\Entity\GameSession;
use App\Entity\Player;
use App\Game\Service\CensusOrderCalculator;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\DefaultActionTrait;

/**
 * Read-only display of the movement-phase play order: no writable prop, no action.
 * Re-rendered by a Mercure ping whenever the operator console changes the game.
 */
#[AsLiveComponent(template: 'organisms/censusView.html.twig')]
final class CensusView
{
    use DefaultActionTrait;

    #[LiveProp]
    public GameSession $game; // @phpstan-ignore property.uninitialized (hydrated by LiveComponent via reflection before use)

    public function __construct(
        private readonly CensusOrderCalculator $censusOrderCalculator,
    ) {}

    /** @return list<Player> */
    public function getOrderedPlayers(): array
    {
        return $this->censusOrderCalculator->orderFor($this->game);
    }

    public function isMilitary(Player $player): bool
    {
        return $this->censusOrderCalculator->hasMilitary($player);
    }
}
