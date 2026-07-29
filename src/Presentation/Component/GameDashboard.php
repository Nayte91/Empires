<?php

declare(strict_types=1);

namespace App\Presentation\Component;

use App\Rules\Ruleset\AstEraDefinition;
use App\Rules\Ruleset\AstRegistry;
use App\State\Game;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

/** Static shell containing game metadata and embedded live components (AST, Roster). */
#[AsTwigComponent(template: 'organisms/gameDashboard.html.twig')]
final class GameDashboard
{
    /** Which panel the phone opens on, and the only tabs the bar will honour. */
    public const array TABS = ['nav', 'score', 'ast'];

    public Game $game; // @phpstan-ignore property.uninitialized (hydrated by TwigComponent via reflection before use)

    /**
     * The tab lives in the URL rather than in storage: a reload mid-game lands back where the
     * operator left it, and the link that does so can be handed to somebody else.
     */
    public string $tab = 'nav';

    public function __construct(private readonly AstRegistry $astRegistry) {}

    /** Anything the query string invents falls back to the tab the phone opens on. */
    public function getCurrentTab(): string
    {
        return \in_array($this->tab, self::TABS, true) ? $this->tab : 'nav';
    }

    /** @return list<AstEraDefinition> what each era of the board asks of an empire, listed under it */
    public function getAstEras(): array
    {
        return $this->astRegistry->getEras();
    }
}
