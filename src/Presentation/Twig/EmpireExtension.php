<?php

declare(strict_types=1);

namespace App\Presentation\Twig;

use App\Rules\Ruleset\Empire;
use App\Rules\Ruleset\EmpireRegistry;
use Twig\Attribute\AsTwigFilter;

final readonly class EmpireExtension
{
    public function __construct(private EmpireRegistry $empireRegistry) {}

    #[AsTwigFilter('empire_demonym')]
    public function getDemonym(string $slug): string
    {
        return $this->empire($slug)->demonym;
    }

    #[AsTwigFilter('empire_adjective')]
    public function getAdjective(string $slug): string
    {
        return $this->empire($slug)->adjective;
    }

    private function empire(string $slug): Empire
    {
        return $this->empireRegistry->findByName($slug)
            ?? throw new \RuntimeException(sprintf('No empire named "%s" in the ruleset.', $slug));
    }
}
