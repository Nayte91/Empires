<?php

declare(strict_types=1);

namespace App\Presentation\Twig;

use App\Rules\Ruleset\EmpireRegistry;
use Twig\Attribute\AsTwigFilter;

final readonly class EmpireExtension
{
    public function __construct(private EmpireRegistry $empireRegistry) {}

    #[AsTwigFilter('empire_demonym')]
    public function getDemonym(?string $slug): ?string
    {
        if (null === $slug) {
            return null;
        }

        return $this->empireRegistry->findByName($slug)?->demonym;
    }

    #[AsTwigFilter('empire_adjective')]
    public function getAdjective(?string $slug): ?string
    {
        if (null === $slug) {
            return null;
        }

        return $this->empireRegistry->findByName($slug)?->adjective;
    }
}
