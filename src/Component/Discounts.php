<?php

declare(strict_types=1);

namespace App\Component;

use App\Entity\Advance;
use App\Entity\Player;
use App\Repository\AdvanceRepository;
use App\Shop\Service\PriceCalculator;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

#[AsTwigComponent(name: 'molecules:Discounts', template: 'molecules/Discounts.html.twig')]
final class Discounts
{
    public Player $player; // @phpstan-ignore property.uninitialized (hydrated by TwigComponent via reflection before use)

    public function __construct(
        private readonly PriceCalculator $priceCalculator,
        private readonly AdvanceRepository $advanceRepository,
    ) {}

    /** @return array{categories: array<string, int>, named: array<string, int>} */
    public function getCredits(): array
    {
        /** @var list<Advance> $owned */
        $owned = array_values($this->advanceRepository->getAdvancesByNames($this->player->advances));

        return $this->priceCalculator->creditsFor($owned);
    }

    /** @return array<string, string> */
    public function getCategoryColors(): array
    {
        return $this->advanceRepository->getCategoryColors();
    }
}
