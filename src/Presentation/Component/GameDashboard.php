<?php

declare(strict_types=1);

namespace App\Presentation\Component;

use App\Rules\Ruleset\AstEraDefinition;
use App\Rules\Ruleset\AstRegistry;
use App\State\Game;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

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
