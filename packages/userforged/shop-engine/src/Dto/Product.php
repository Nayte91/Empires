<?php

declare(strict_types=1);

namespace Userforged\ShopEngine\Dto;

final readonly class Product
{
    public function __construct(
        public string $key,
        public int $netCost,
        public bool $owned,
        public bool $inCart,
    ) {}
}
