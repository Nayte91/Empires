<?php

declare(strict_types=1);

namespace App\Presentation\Component;

use App\Rules\Ruleset\AdvanceRegistry;
use App\Rules\Ruleset\Empire;
use App\Rules\Ruleset\EmpireRegistry;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

/** Single routing point config yaml → display for colors: emits empire and advance-category colors as CSS custom properties. */
#[AsTwigComponent(template: 'atoms/ThemeColors.html.twig')]
final readonly class ThemeColors
{
    public function __construct(
        private EmpireRegistry $empireRegistry,
        private AdvanceRegistry $advanceRegistry,
    ) {}

    /** @return array<string, string> empire slug => hex color */
    public function getEmpireColors(): array
    {
        return array_map(
            static fn (Empire $empire): string => $empire->color,
            $this->empireRegistry->findAll()
        );
    }

    /** @return array<string, string> category key => hex color */
    public function getAdvanceCategoryColors(): array
    {
        return $this->advanceRegistry->getCategoryColors();
    }
}
