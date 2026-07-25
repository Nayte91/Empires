<?php

declare(strict_types=1);

namespace Userforged\ShopEngine\Promotion;

final readonly class ElectiveBenefit
{
    public function __construct(
        public int $budget,
        public int $step,
    ) {}
}
