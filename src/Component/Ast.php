<?php

declare(strict_types=1);

namespace App\Component;

use App\Entity\GameSession;
use App\Entity\Player;
use App\Game\AstCatalog;
use App\Game\Dto\AstEraDefinition;
use App\Game\EmpireCatalog;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\DefaultActionTrait;

/**
 * Read-only display of the Archaeological Succession Table: no writable prop, no action.
 * Re-rendered by a Mercure ping whenever the operator console changes the game.
 */
#[AsLiveComponent(template: 'molecules/ast.html.twig')]
final class Ast
{
    use DefaultActionTrait;

    #[LiveProp]
    public GameSession $game; // @phpstan-ignore property.uninitialized (hydrated by LiveComponent via reflection before use)

    public function __construct(
        private readonly AstCatalog $astCatalog,
        private readonly EmpireCatalog $empireCatalog,
    ) {}

    public function getTrackLength(): int
    {
        return $this->astCatalog->getTrackLength();
    }

    /** @return list<AstEraDefinition> */
    public function getEras(): array
    {
        return $this->astCatalog->getEras();
    }

    /** @return list<AstEraDefinition> one entry per track position, in file order */
    public function getColumnEras(): array
    {
        return $this->astCatalog->getColumnEras();
    }

    /** @return list<Player> ranked by empire position on the A.S.T. */
    public function getRankedPlayers(): array
    {
        $players = $this->game->players->toArray();
        usort($players, fn (Player $a, Player $b): int => $this->empireCatalog->positionOf($a->empire) <=> $this->empireCatalog->positionOf($b->empire));

        return $players;
    }
}
