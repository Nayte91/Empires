<?php

declare(strict_types=1);

namespace App\Presentation\Component;

use App\Rules\Ruleset\Advance;
use App\Rules\Ruleset\AdvanceCatalog;
use App\Rules\Shop\ShopConnector;
use App\State\Player;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;
use Userforged\ShopEngine\Cart;
use Userforged\ShopEngine\CartStorageInterface;
use Userforged\ShopEngine\Dto\Product;
use Userforged\ShopEngine\Service\ProductCatalog;

#[AsTwigComponent(name: 'organisms:ProductGrid', template: 'organisms/productGrid.html.twig')]
final class ProductGrid
{
    public Player $player; // @phpstan-ignore property.uninitialized (hydrated by TwigComponent via reflection before use)
    public string $storageKey; // @phpstan-ignore property.uninitialized (hydrated by TwigComponent via reflection before use)
    public bool $locked = false;
    public bool $compact = false;

    public function __construct(
        private readonly AdvanceCatalog $advanceCatalog,
        private readonly CartStorageInterface $cartStorage,
        private readonly ProductCatalog $productCatalog,
        private readonly ShopConnector $shopConnector,
    ) {}

    /** @return list<array{advance: Advance, product: Product}> */
    public function getRows(): array
    {
        $advancesByKey = [];

        foreach ($this->advanceCatalog->getAdvances() as $advance) {
            $advancesByKey[$advance->key] = $advance;
        }

        $products = $this->productCatalog->productsFor(
            $this->shopConnector->buyerFor($this->player),
            $this->getCart()->keys(),
        );

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
