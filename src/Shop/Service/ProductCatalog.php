<?php

declare(strict_types=1);

namespace App\Shop\Service;

use App\Shop\BuyerInterface;
use App\Shop\Dto\Product;
use App\Shop\ProductInterface;
use App\Shop\ProductProviderInterface;

final readonly class ProductCatalog
{
    public function __construct(
        private ProductProviderInterface $productProvider,
        private PriceCalculator $priceCalculator,
    ) {}

    /**
     * Catalogue minus the buyer's already-owned advances — a cashier has no
     * use for re-selling what a player already has.
     *
     * @param list<string> $inCartKeys
     *
     * @return list<Product>
     */
    public function productsFor(BuyerInterface $buyer, array $inCartKeys): array
    {
        $ownedAdvances = $this->productProvider->productsByKeys($buyer->ownedKeys);
        $bonusCredits = $buyer->electiveCredits;

        return array_map(
            fn (ProductInterface $advance): ?Product => \in_array($advance->key, $buyer->ownedKeys, true)
                    ? null
                    : new Product(
                        key: $advance->key,
                        netCost: $this->priceCalculator->netCost($advance, $ownedAdvances, $bonusCredits),
                        owned: false,
                        inCart: \in_array($advance->key, $inCartKeys, true),
                    ),
            $this->productProvider->products(),
        )
                |> array_filter(...)
                |> array_values(...);
    }
}
