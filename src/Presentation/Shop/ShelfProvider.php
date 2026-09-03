<?php

declare(strict_types=1);

namespace App\Presentation\Shop;

use App\Rules\Ruleset\Advance;
use App\Rules\Ruleset\AdvanceRegistry;
use App\Rules\Shop\ShopConnector;
use App\State\Player;
use Userforged\ShopEngine\CartStorageInterface;
use Userforged\ShopEngine\Dto\Product;
use Userforged\ShopEngine\Service\ProductCatalog;

/**
 * The repository-shaped query behind the shelf: which advances a player can still buy, at what
 * price, in what order. It lives in Presentation because the order is a display concern
 * (CatalogSort), not a rule — and it cannot live in the shop-engine package because Product carries
 * only the net cost; name and list price belong to Advance, a type the package must not name.
 */
final readonly class ShelfProvider
{
    public function __construct(
        private AdvanceRegistry $advanceRegistry,
        private CartStorageInterface $cartStorage,
        private ProductCatalog $productCatalog,
        private ShopConnector $shopConnector,
    ) {}

    /** @return list<array{advance: Advance, product: Product}> */
    public function rowsFor(Player $player, CatalogView $view, string $storageKey): array
    {
        $advancesByKey = [];

        foreach ($this->advanceRegistry->getAdvances() as $advance) {
            $advancesByKey[$advance->key] = $advance;
        }

        // A product sitting in the cart leaves the grid entirely rather than lingering disabled:
        // the cart is already showing it, and a shelf that still displays what you have picked up
        // makes the remaining choice harder to read.
        $products = array_values(array_filter(
            $this->productCatalog->productsFor(
                $this->shopConnector->buyerFor($player),
                $this->cartStorage->load($storageKey)->keys(),
            ),
            static fn (Product $product): bool => !$product->inCart,
        ));

        $rows = array_map(
            static fn (Product $product): array => ['advance' => $advancesByKey[$product->key], 'product' => $product],
            $products,
        );

        // Stable since PHP 8.0, so equal prices keep the order they arrived in — which is
        // AdvanceRegistry's own list-price sort. No tie-breaker to invent.
        usort($rows, $view->sort->compare(...));

        return $rows;
    }
}
