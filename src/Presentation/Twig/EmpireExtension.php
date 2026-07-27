<?php

declare(strict_types=1);

namespace App\Presentation\Twig;

use App\Rules\Ruleset\EmpireCatalog;
use Twig\Attribute\AsTwigFilter;

final readonly class EmpireExtension
{
    public function __construct(private EmpireCatalog $empireCatalog) {}

    #[AsTwigFilter('empire_demonym')]
    public function getDemonym(?string $slug): ?string
    {
        if (null === $slug) {
            return null;
        }

        return $this->empireCatalog->findByName($slug)?->demonym;
    }

    #[AsTwigFilter('empire_adjective')]
    public function getAdjective(?string $slug): ?string
    {
        if (null === $slug) {
            return null;
        }

        return $this->empireCatalog->findByName($slug)?->adjective;
    }
}
