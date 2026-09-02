<?php

declare(strict_types=1);

namespace App\Presentation\Component;

use App\Rules\Ruleset\AstEraDefinition;
use App\Rules\Ruleset\AstRegistry;
use App\Rules\StandingsCalculator;
use App\State\ASTVersion;
use App\State\Game;
use App\State\Player;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

#[AsTwigComponent(template: 'molecules/Ast.html.twig')]
final class Ast
{
    // Shared board layout is deliberately approximated to the 'standard' track — genuinely
    // correct per-empire column widths (each empire's own era boundaries) is a separate,
    // larger redesign. Per-player marker info (getEraNameFor()) stays fully accurate though.
    private const string SHARED_LAYOUT_GROUP = 'standard';

    public Game $game; // @phpstan-ignore property.uninitialized (hydrated by TwigComponent via reflection before use)

    public function __construct(
        private readonly AstRegistry $astRegistry,
        private readonly StandingsCalculator $standingsCalculator,
    ) {}

    /**
     * Start and the Stone Age ask nothing of anybody, so the compact board drops those columns. The
     * span is read off the requirements rather than counted in, and the expert track opens the same
     * way.
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
