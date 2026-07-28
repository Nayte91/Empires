<?php

declare(strict_types=1);

namespace App\Presentation\Component;

use App\Rules\Ruleset\AstEraDefinition;
use App\Rules\Ruleset\AstRegistry;
use App\Rules\StandingsCalculator;
use App\State\ASTVersion;
use App\State\Game;
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
    public Game $game; // @phpstan-ignore property.uninitialized (hydrated by LiveComponent via reflection before use)

    public function __construct(
        private readonly AstRegistry $astRegistry,
        private readonly StandingsCalculator $standingsCalculator,
    ) {}

    /**
     * The leading columns the compact board drops: the opening stretch nobody has to earn — Start
     * and the Stone Age ask nothing of anyone — so it separates no empire from another. Derived
     * from the requirements rather than counted in, so a rules change carries the board with it.
     */
    public function getOpeningSpan(): int
    {
        $span = 0;

        foreach ($this->astRegistry->getColumnEras($this->game->astVersion, self::SHARED_LAYOUT_GROUP) as $era) {
            $requirements = ASTVersion::EXPERT === $this->game->astVersion ? $era->expertRequirements : $era->basicRequirements;

            if ([] !== $requirements) {
                break;
            }

            ++$span;
        }

        return $span;
    }

    public function scoreOf(Player $player): int
    {
        return $this->standingsCalculator->scoreOf($player);
    }

    /**
     * The podium colour of the player's score, or null when they are off it. A player who has not
     * scored yet is not leading anything, so an all-zero board wears no medal at all.
     */
    public function medalOf(Player $player): ?string
    {
        if (0 === $this->scoreOf($player)) {
            return null;
        }

        return match ($this->standingsCalculator->rankOf($player)) {
            1 => 'gold',
            2 => 'silver',
            3 => 'bronze',
            default => null,
        };
    }

    public function getTrackLength(): int
    {
        return $this->astRegistry->getTrackLength($this->game->astVersion, self::SHARED_LAYOUT_GROUP);
    }

    /** @return list<array{era: AstEraDefinition, span: int}> */
    public function getEraHeaders(): array
    {
        $spans = $this->astRegistry->getColumnEras($this->game->astVersion, self::SHARED_LAYOUT_GROUP);
        $counts = [];

        foreach ($spans as $era) {
            $counts[$era->key] = ($counts[$era->key] ?? 0) + 1;
        }

        return array_map(
            static fn (AstEraDefinition $era): array => ['era' => $era, 'span' => $counts[$era->key] ?? 0],
            $this->astRegistry->getEras(),
        );
    }

    /** @return list<AstEraDefinition> one entry per track position, in file order, using this player's own empire group */
    public function getColumnErasFor(Player $player): array
    {
        $group = $this->astRegistry->resolveEmpireGroup($this->game->astVersion, $player->empire);

        return $this->astRegistry->getColumnEras($this->game->astVersion, $group);
    }

    public function getEraNameFor(Player $player): string
    {
        $group = $this->astRegistry->resolveEmpireGroup($this->game->astVersion, $player->empire);

        return $this->astRegistry->getEraForPosition($player->astPosition, $this->game->astVersion, $group)->name;
    }

    /** @return list<Player> the leader first, so the board is read top-down as the standings */
    public function getRankedPlayers(): array
    {
        return $this->standingsCalculator->standings($this->game);
    }
}
