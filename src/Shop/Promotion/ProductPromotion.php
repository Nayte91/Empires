<?php

declare(strict_types=1);

namespace App\Shop\Promotion;

final readonly class ProductPromotion
{
    /**
     * @param array<string, int> $gift
     * @param array<string, int> $discount
     */
    public function __construct(
        public array $gift = [],
        public array $discount = [],
        public ?ElectiveBenefit $option = null,
    ) {}
}
