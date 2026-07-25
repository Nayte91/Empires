<?php

declare(strict_types=1);

namespace App\Component;

use App\Entity\Player;
use App\Shop\Cart;
use App\Shop\CartRepository;
use App\Shop\Dto\Product;
use App\Shop\Service\ProductCatalog;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

#[AsTwigComponent(name: 'organisms:ProductGrid', template: 'organisms/productGrid.html.twig')]
final class ProductGrid
{
    public Player $player; // @phpstan-ignore property.uninitialized (hydrated by TwigComponent via reflection before use)
    public string $storageKey; // @phpstan-ignore property.uninitialized (hydrated by TwigComponent via reflection before use)
    public bool $locked = false;
    public bool $compact = false;

    public function __construct(
        private readonly CartRepository $cartRepository,
        private readonly ProductCatalog $productCatalog,
    ) {}

    /** @return list<Product> */
    public function getProducts(): array
    {
        return $this->productCatalog->productsFor(
            $this->player,
            $this->getCart()->keys(),
        );
    }

    private function getCart(): Cart
    {
        return $this->cartRepository->findOrCreate($this->storageKey);
    }
}
