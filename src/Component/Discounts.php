<?php

declare(strict_types=1);

namespace App\Component;

use App\Entity\Player;
use App\Game\Shop\AdvanceCreditsCalculator;
use App\Game\Shop\ShopConnector;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

#[AsTwigComponent(name: 'molecules:Discounts', template: 'molecules/Discounts.html.twig')]
final class Discounts
{
    public Player $player; // @phpstan-ignore property.uninitialized (hydrated by TwigComponent via reflection before use)

    public function __construct(
        private readonly AdvanceCreditsCalculator $creditsCalculator,
        private readonly ShopConnector $shopConnector,
    ) {}

    /** @return array{facets: array<string, int>, named: array<string, int>} */
    public function getCredits(): array
    {
        $buyer = $this->shopConnector->buyerFor($this->player);

        return $this->creditsCalculator->creditsFor($buyer->entitlements, $this->shopConnector->facets());
    }
}
