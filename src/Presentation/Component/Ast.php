<?php

declare(strict_types=1);

namespace App\Presentation\Component;

use App\Rules\Ruleset\AstCatalog;
use App\Rules\Ruleset\AstEraDefinition;
use App\Rules\Ruleset\EmpireCatalog;
use App\State\GameSession;
use App\State\Player;
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

    // Shared board layout is deliberately approximated to the 'standard' track — genuinely
    // correct per-empire column widths (each empire's own era boundaries) is a separate,
    // larger redesign. Per-player marker info (getEraNameFor()) stays fully accurate though.
    private const string SHARED_LAYOUT_GROUP = 'standard';

    #[LiveProp]
    public GameSession $game; // @phpstan-ignore property.uninitialized (hydrated by LiveComponent via reflection before use)

    public function __construct(
        private readonly AstCatalog $astCatalog,
        private readonly EmpireCatalog $empireCatalog,
    ) {}

    public function getTrackLength(): int
    {
        return $this->astCatalog->getTrackLength($this->game->astVersion, self::SHARED_LAYOUT_GROUP);
    }

    /** @return list<array{era: AstEraDefinition, span: int}> */
    public function getEraHeaders(): array
    {
        $spans = $this->astCatalog->getColumnEras($this->game->astVersion, self::SHARED_LAYOUT_GROUP);
        $counts = [];

        foreach ($spans as $era) {
            $counts[$era->key] = ($counts[$era->key] ?? 0) + 1;
        }

        return array_map(
            static fn (AstEraDefinition $era): array => ['era' => $era, 'span' => $counts[$era->key] ?? 0],
            $this->astCatalog->getEras(),
        );
    }

    /** @return list<AstEraDefinition> one entry per track position, in file order, using this player's own empire group */
    public function getColumnErasFor(Player $player): array
    {
        $group = $this->astCatalog->resolveEmpireGroup($this->game->astVersion, $player->empire);

        return $this->astCatalog->getColumnEras($this->game->astVersion, $group);
    }

    public function getEraNameFor(Player $player): string
    {
        $group = $this->astCatalog->resolveEmpireGroup($this->game->astVersion, $player->empire);

        return $this->astCatalog->getEraForPosition($player->astPosition, $this->game->astVersion, $group)->name;
    }

    /** @return list<Player> ranked by empire position on the A.S.T. */
    public function getRankedPlayers(): array
    {
        $players = $this->game->players->toArray();
        usort($players, fn (Player $a, Player $b): int => $this->empireCatalog->positionOf($a->empire) <=> $this->empireCatalog->positionOf($b->empire));

        return $players;
    }
}
