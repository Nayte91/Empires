<?php

declare(strict_types=1);

namespace App\Component;

use App\DTO\AstEraDefinition;
use App\Entity\Game;
use App\Repository\AstRepository;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

/** Read-only display of the Archaeological Succession Table: no writable prop, no action. */
#[AsTwigComponent(template: 'molecules/ast.html.twig')]
final class Ast
{
    public Game $game; // @phpstan-ignore property.uninitialized (hydrated by TwigComponent via reflection before use)

    public function __construct(
        private readonly AstRepository $astRepository,
    ) {}

    public function getTrackLength(): int
    {
        return $this->astRepository->getTrackLength();
    }

    /** @return list<AstEraDefinition> */
    public function getEras(): array
    {
        return $this->astRepository->getEras();
    }

    /** @return list<AstEraDefinition> one entry per track position, in file order */
    public function getColumnEras(): array
    {
        return $this->astRepository->getColumnEras();
    }
}
