<?php

declare(strict_types=1);

namespace Userforged\ShopEngine\Service;

use Userforged\ShopEngine\BuyerInterface;
use Userforged\ShopEngine\Dto\Product;
use Userforged\ShopEngine\ProductInterface;
use Userforged\ShopEngine\ProductProviderInterface;

final readonly class ProductCatalog
{
    public function __construct(
        private ProductProviderInterface $productProvider,
        private PriceCalculator $priceCalculator,
    ) {}

    /**
     * Catalogue minus the buyer's already-owned products — a cashier has no
     * use for re-selling what a buyer already has.
     *
     * @param list<string> $inCartKeys
     *
     * @return list<Product>
     */
    public function productsFor(BuyerInterface $buyer, array $inCartKeys): array
    {
        return array_map(
            fn (ProductInterface $product): ?Product => \in_array($product->key, $buyer->ownedKeys, true)
                    ? null
                    : new Product(
                        key: $product->key,
                        netCost: $this->priceCalculator->netCost($product, $buyer),
                        owned: false,
                        inCart: \in_array($product->key, $inCartKeys, true),
                    ),
            $this->productProvider->products(),
        )
                |> array_filter(...)
                |> array_values(...);
    }
}
