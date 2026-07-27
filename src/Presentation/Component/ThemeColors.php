<?php

declare(strict_types=1);

namespace App\Presentation\Component;

use App\Rules\Ruleset\AdvanceCatalog;
use App\Rules\Ruleset\Empire;
use App\Rules\Ruleset\EmpireCatalog;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

/** Single routing point config yaml → display for colors: emits empire and advance-category colors as CSS custom properties. */
#[AsTwigComponent(template: 'atoms/themeColors.html.twig')]
final readonly class ThemeColors
{
    public function __construct(
        private EmpireCatalog $empireCatalog,
        private AdvanceCatalog $advanceCatalog,
    ) {}

    /** @return array<string, string> empire slug => hex color */
    public function getEmpireColors(): array
    {
        return array_map(
            static fn (Empire $empire): string => $empire->color,
            $this->empireCatalog->findAll()
        );
    }

    /** @return array<string, string> category key => hex color */
    public function getAdvanceCategoryColors(): array
    {
        return $this->advanceCatalog->getCategoryColors();
    }
}
