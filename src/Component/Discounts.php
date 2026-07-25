<?php

declare(strict_types=1);

namespace App\Component;

use App\Entity\Player;
use App\Game\AdvanceCatalog;
use App\Game\Dto\Advance;
use App\Game\Shop\ShopConnector;
use App\Shop\Service\PriceCalculator;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

#[AsTwigComponent(name: 'molecules:Discounts', template: 'molecules/Discounts.html.twig')]
final class Discounts
{
    public Player $player; // @phpstan-ignore property.uninitialized (hydrated by TwigComponent via reflection before use)

    public function __construct(
        private readonly PriceCalculator $priceCalculator,
        private readonly AdvanceCatalog $advanceCatalog,
        private readonly ShopConnector $shopConnector,
    ) {}

    /** @return array{facets: array<string, int>, named: array<string, int>} */
    public function getCredits(): array
    {
        /** @var list<Advance> $owned */
        $owned = array_values($this->advanceCatalog->getAdvancesByNames($this->player->advances));
        $buyer = $this->shopConnector->buyerFor($this->player);

        return $this->priceCalculator->creditsFor($owned, $buyer->electiveCredits, $this->shopConnector->facets());
    }

    /** @return array<string, string> */
    public function getCategoryColors(): array
    {
        return $this->advanceCatalog->getCategoryColors();
    }
}
