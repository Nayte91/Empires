<?php

declare(strict_types=1);

namespace App\Presentation\Component;

use App\Rules\Ruleset\AstEraDefinition;
use App\Rules\Ruleset\AstRegistry;
use App\State\Game;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

/**
 * Static shell containing game metadata and embedded live components (AST, Roster).
 *
 * Which tab is open is the browser's business end to end — the server neither reads it nor writes
 * it — so the query string is not a way in: a link naming a tab is served the roster like any
 * other, and nothing downstream may build `?tab=` URLs.
 *
 * The chronicle serves the same address once the game is finished, and drops three things: the
 * roster, which reads live counters a finished game has none of; the way in to the operator
 * console, whose every control refuses a finished game; and the A.S.T. requirements, which are
 * advice for a game still on — the board itself stays, as the record of the game that was played.
 */
#[AsTwigComponent(template: 'organisms/GameDashboard.html.twig')]
final class GameDashboard
{
    public Game $game; // @phpstan-ignore property.uninitialized (hydrated by TwigComponent via reflection before use)

    public function __construct(private readonly AstRegistry $astRegistry) {}

    /** @return list<AstEraDefinition> what each era of the board asks of an empire, listed under it */
    public function getAstEras(): array
    {
        return $this->astRegistry->getEras();
    }
}
