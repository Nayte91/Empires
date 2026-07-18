<?php

declare(strict_types=1);

namespace App\Component;

use App\Entity\GameSession;
use App\Game\AstCatalog;
use App\Game\Dto\AstEraDefinition;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

/** Read-only display of the Archaeological Succession Table: no writable prop, no action. */
#[AsTwigComponent(template: 'molecules/ast.html.twig')]
final class Ast
{
    public GameSession $game; // @phpstan-ignore property.uninitialized (hydrated by TwigComponent via reflection before use)

    public function __construct(
        private readonly AstCatalog $astCatalog,
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
}
