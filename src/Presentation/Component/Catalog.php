<?php

declare(strict_types=1);

namespace App\Presentation\Component;

use App\Rules\Ruleset\Advance;
use App\Rules\Ruleset\AdvanceRegistry;
use App\Rules\Shop\ShopConnector;
use App\State\Player;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;
use Userforged\ShopEngine\Cart;
use Userforged\ShopEngine\CartStorageInterface;
use Userforged\ShopEngine\Dto\Product;
use Userforged\ShopEngine\Service\ProductCatalog;

#[AsTwigComponent(name: 'organisms:Catalog', template: 'organisms/catalog.html.twig')]
final class Catalog
{
    public Player $player; // @phpstan-ignore property.uninitialized (hydrated by TwigComponent via reflection before use)
    public string $storageKey; // @phpstan-ignore property.uninitialized (hydrated by TwigComponent via reflection before use)
    public bool $locked = false;
    public bool $compact = false;

    public function __construct(
        private readonly AdvanceRegistry $advanceRegistry,
        private readonly CartStorageInterface $cartStorage,
        private readonly ProductCatalog $productCatalog,
        private readonly ShopConnector $shopConnector,
    ) {}

    /** @return list<array{advance: Advance, product: Product}> */
    public function getRows(): array
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
                $this->shopConnector->buyerFor($this->player),
                $this->getCart()->keys(),
            ),
            static fn (Product $product): bool => !$product->inCart,
        ));

        return array_map(
            static fn (Product $product): array => ['advance' => $advancesByKey[$product->key], 'product' => $product],
            $products,
        );
    }

    private function getCart(): Cart
    {
        return $this->cartStorage->load($this->storageKey);
    }
}
