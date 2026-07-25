<?php

declare(strict_types=1);

namespace App\Game\Dto;

use App\Shop\Promotion\ElectiveBenefit;

final readonly class Promotion
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
