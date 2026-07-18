<?php

declare(strict_types=1);

namespace App\Shop\Dto;

use App\Game\Dto\Advance;

final readonly class Product
{
    public function __construct(
        public Advance $advance,
        public int $netCost,
        public bool $owned,
        public bool $inCart,
    ) {}

    /**
     * @param list<self>   $products
     * @param list<string> $keys
     *
     * @return list<self>
     */
    public static function filterByKeys(array $products, array $keys): array
    {
        $byKey = [];

        foreach ($products as $product) {
            $byKey[$product->advance->key] = $product;
        }

        return array_values(array_filter(array_map(
            static fn (string $key): ?self => $byKey[$key] ?? null,
            $keys,
        )));
    }
}
