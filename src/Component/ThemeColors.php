<?php

declare(strict_types=1);

namespace App\Component;

use App\DTO\Empire;
use App\Repository\AdvanceRepository;
use App\Repository\EmpireRepository;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

/** Single routing point config yaml → display for colors: emits empire and advance-category colors as CSS custom properties. */
#[AsTwigComponent(template: 'atoms/themeColors.html.twig')]
final readonly class ThemeColors
{
    public function __construct(
        private EmpireRepository $empireRepository,
        private AdvanceRepository $advanceRepository,
    ) {}

    /** @return array<string, string> empire slug => hex color */
    public function getEmpireColors(): array
    {
        return array_map(
            static fn (Empire $empire): string => $empire->color,
            $this->empireRepository->findAll()
        );
    }

    /** @return array<string, string> category key => hex color */
    public function getAdvanceCategoryColors(): array
    {
        return $this->advanceRepository->getCategoryColors();
    }
}
