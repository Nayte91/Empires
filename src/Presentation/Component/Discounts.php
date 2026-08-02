<?php

declare(strict_types=1);

namespace App\Presentation\Component;

use App\Rules\Ruleset\AdvanceRegistry;
use App\Rules\Shop\AdvanceCreditsCalculator;
use App\Rules\Shop\ShopConnector;
use App\State\Player;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

#[AsTwigComponent(name: 'molecules:Discounts', template: 'molecules/Discounts.html.twig')]
final class Discounts
{
    public Player $player; // @phpstan-ignore property.uninitialized (hydrated by TwigComponent via reflection before use)

    public function __construct(
        private readonly AdvanceCreditsCalculator $creditsCalculator,
        private readonly ShopConnector $shopConnector,
        private readonly AdvanceRegistry $advanceRegistry,
    ) {}

    /** @return array{facets: array<string, array{amount: int, spent: bool}>, named: array<string, array{amount: int, spent: bool}>} */
    public function getCredits(): array
    {
        $buyer = $this->shopConnector->buyerFor($this->player);

        return $this->creditsCalculator->creditsFor(
            $buyer->entitlements,
            $this->shopConnector->facets(),
            $buyer->ownedKeys,
            $this->advanceRegistry->getAdvances(),
        );
    }
}
