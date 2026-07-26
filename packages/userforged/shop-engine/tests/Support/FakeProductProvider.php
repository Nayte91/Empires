<?php

declare(strict_types=1);

namespace Userforged\ShopEngine\Tests\Support;

use Userforged\ShopEngine\ProductInterface;
use Userforged\ShopEngine\ProductProviderInterface;

final readonly class FakeProductProvider implements ProductProviderInterface
{
    /** @param list<ProductInterface> $products */
    public function __construct(
        private array $products = [],
    ) {}

    public function products(): array
    {
        return $this->products;
    }

    /** @param list<string> $keys */
    public function productsByKeys(array $keys): array
    {
        return array_values(array_filter(
            $this->products,
            static fn (ProductInterface $product): bool => \in_array($product->key, $keys, true),
        ));
    }
}
