<?php

declare(strict_types=1);

namespace App\Shop\Service;

use App\Entity\Player;
use App\Game\AdvanceCatalog;
use App\Game\Dto\Advance;
use App\Shop\Dto\Product;
use App\Shop\Promotion\OptionCredits;

final readonly class ProductCatalog
{
    public function __construct(
        private AdvanceCatalog $advanceCatalog,
        private PriceCalculator $priceCalculator,
        private OptionCredits $optionCredits,
    ) {}

    /**
     * Catalogue minus the player's already-owned advances — a cashier has no
     * use for re-selling what a player already has.
     *
     * @param list<string> $inCartKeys
     *
     * @return list<Product>
     */
    public function productsFor(Player $player, array $inCartKeys): array
    {
        /** @var list<Advance> $ownedAdvances */
        $ownedAdvances = $this->advanceCatalog->getAdvancesByNames($player->advances);
        $bonusCredits = $this->optionCredits->forPlayer($player);

        return array_map(
            fn (Advance $advance): ?Product => \in_array($advance->key, $player->advances, true)
                    ? null
                    : new Product(
                        advance: $advance,
                        netCost: $this->priceCalculator->netCost($advance, $ownedAdvances, $bonusCredits),
                        owned: false,
                        inCart: \in_array($advance->key, $inCartKeys, true),
                    ),
            $this->advanceCatalog->getAdvances(),
        )
                |> array_filter(...)
                |> array_values(...);
    }
}
