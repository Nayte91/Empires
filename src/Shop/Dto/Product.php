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
}
